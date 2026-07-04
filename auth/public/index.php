<?php

/**
 * auth.lvh.me — the LTI service. Validates launches and establishes the session.
 *
 *   GET  /lti/login   OIDC 3rd-party login initiation (hop 1 -> redirect to platform)
 *   POST /lti/launch  validate the id_token (hop 3), create session, set cookie, -> app
 *   GET  /lti/jwks    this tool's public keyset
 *
 * The session identity lives in the shared store (/sessions); the api service
 * reads it. The cookie is Domain=lvh.me so it reaches app.lvh.me + api.lvh.me.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Cache;
use App\Cookie;
use App\Database;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Packback\Lti1p3\Factories\MessageFactory;
use Packback\Lti1p3\JwksEndpoint;
use Packback\Lti1p3\LtiOidcLogin;
use Packback\Lti1p3\LtiServiceConnector;

const ISSUER = 'https://localhost';
const APP_URL = 'https://app.lvh.me';

/**
 * HTTP client for server-to-server calls to Moodle (JWKS fetch, token, AGS/NRPS).
 *
 * LOCAL-DEV SHIM: the tool reaches Moodle over the Docker network as
 * http://moodle:8080, but Moodle canonicalises to its wwwroot (https://localhost)
 * and 303-redirects anything else. So we rewrite each outgoing request to present
 * Host: localhost + X-Forwarded-Proto: https (Moodle trusts the latter because
 * SSLPROXY=true). In production the LMS is a real public host and none of this is needed.
 */
function platformHttpClient(): Client
{
    $stack = HandlerStack::create();
    $stack->push(Middleware::mapRequest(
        fn ($r) => $r->withHeader('Host', 'localhost')->withHeader('X-Forwarded-Proto', 'https')
    ));

    return new Client(['handler' => $stack]);
}

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/') ?: '/';

$db = new Database();
$cache = new Cache();
$cookie = new Cookie();

try {
    switch ($path) {

        // ---- Hop 1: OIDC login initiation -------------------------------------
        case '/lti/login':
            $launchUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/lti/launch';
            $redirectUrl = LtiOidcLogin::new($db, $cache, $cookie)
                ->getRedirectUrl($launchUrl, $_REQUEST);
            header('Location: ' . $redirectUrl);
            exit;

        // ---- Hop 3: validate launch, establish the session, hand off to app ---
        case '/lti/launch':
            $connector = new LtiServiceConnector($cache, platformHttpClient());
            $message = (new MessageFactory($db, $connector, $cache, $cookie))
                ->create($_REQUEST);

            // Session identity -> shared store; cookie carries only an opaque id.
            //   httpOnly     -> JS can't read it (XSS-safe)
            //   Secure       -> HTTPS only
            //   SameSite=Lax -> sent on same-site navigations; survives refresh
            //   Domain=lvh.me-> shared across auth/api/app subdomains
            // Requires a FIRST-PARTY (new-window) launch.
            $sid = bin2hex(random_bytes(32));
            $cache->putSession($sid, buildIdentity($message->getBody()));
            setcookie('pn_session', $sid, [
                'expires' => 0,
                'path' => '/',
                'domain' => 'lvh.me',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            header('Location: ' . APP_URL);
            exit;

        // ---- This tool's public keyset ---------------------------------------
        case '/lti/jwks':
            header('Content-Type: application/json');
            echo json_encode(JwksEndpoint::fromIssuer($db, ISSUER)->getPublicJwks(), JSON_UNESCAPED_SLASHES);
            exit;

        default:
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "auth service — try /lti/login, /lti/launch, /lti/jwks";
            exit;
    }
} catch (\Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1 style="font-family:system-ui;color:#b00">LTI error</h1>';
    echo '<pre style="font-family:ui-monospace;background:#fee;padding:1rem;border-radius:8px;white-space:pre-wrap">'
        . htmlspecialchars($e->getMessage()) . "\n\n" . htmlspecialchars((string) $e) . '</pre>';
    exit;
}

// --------------------------------------------------------------------------

/** Collapse the LTI claim set into the identity our app cares about. */
function buildIdentity(array $claims): array
{
    $L = 'https://purl.imsglobal.org/spec/lti/claim/';
    $roles = $claims[$L . 'roles'] ?? [];
    $isInstructor = (bool) array_filter(
        $roles,
        fn ($r) => str_contains($r, 'Instructor') || str_contains($r, 'Administrator')
    );

    return [
        'iss' => $claims['iss'] ?? null,
        'sub' => $claims['sub'] ?? null,
        'name' => $claims['name'] ?? null,
        'email' => $claims['email'] ?? null,
        'role' => $isInstructor ? 'instructor' : 'learner',
        'roles' => $roles,
        'context' => $claims[$L . 'context'] ?? null,
        'resourceLink' => $claims[$L . 'resource_link'] ?? null,
        'deploymentId' => $claims[$L . 'deployment_id'] ?? null,
        // stash the service endpoints for Sunday (AGS grades / NRPS roster)
        'ags' => $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] ?? null,
        'nrps' => $claims['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice'] ?? null,
        'claims' => $claims, // full set, for the raw view in the app
    ];
}
