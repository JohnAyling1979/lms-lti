<?php

/**
 * auth.lvh.me — the LTI service. Validates launches, establishes the session,
 * and makes LTI Advantage service calls (NRPS/AGS) on the tool's behalf — it's
 * the only service holding the tool's private signing key.
 *
 *   GET  /lti/login        OIDC 3rd-party login initiation
 *   POST /lti/launch       validate the id_token, create session, set cookie, -> app
 *   GET  /lti/jwks         this tool's public keyset
 *   GET  /services/roster  NRPS — course membership (instructors)
 *   POST /services/grade   AGS — push a score to the gradebook (instructors)
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
use Packback\Lti1p3\LtiNamesRolesProvisioningService;
use Packback\Lti1p3\LtiOidcLogin;
use Packback\Lti1p3\LtiServiceConnector;

const ISSUER = 'https://localhost';
const APP_URL = 'https://app.lvh.me';

/**
 * HTTP client for server-to-server calls to Moodle (JWKS, token, NRPS/AGS).
 *
 * LOCAL-DEV SHIM (split-horizon): service URLs arrive from the launch as
 * https://localhost/... but the container can't reach that — Moodle is at
 * moodle:8080 on the Docker network. So we rewrite host localhost -> moodle:8080
 * (http) AND present Host: localhost + X-Forwarded-Proto: https, which Moodle
 * trusts (SSLPROXY=true) and serves without a 303. In prod the LMS is a real
 * public host and none of this exists.
 */
function platformHttpClient(): Client
{
    $stack = HandlerStack::create();
    $stack->push(Middleware::mapRequest(function ($r) {
        $uri = $r->getUri();
        if ($uri->getHost() === 'localhost') {
            $r = $r->withUri($uri->withScheme('http')->withHost('moodle')->withPort(8080));
        }

        return $r->withHeader('Host', 'localhost')->withHeader('X-Forwarded-Proto', 'https');
    }));

    return new Client(['handler' => $stack]);
}

function jsonExit(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/** Read the session the launch established (or 401). */
function requireSession(Cache $cache): array
{
    $identity = $cache->getSession($_COOKIE['pn_session'] ?? '');
    if ($identity === null) {
        jsonExit(['error' => 'unauthenticated'], 401);
    }

    return $identity;
}

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/') ?: '/';

// CORS for the browser-facing service endpoints (called cross-origin from app.lvh.me).
if (str_starts_with($path, '/services/')) {
    header('Access-Control-Allow-Origin: ' . APP_URL);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
        exit;
    }
}

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
            //   httpOnly / Secure / SameSite=Lax / Domain=lvh.me (shared subdomains)
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

        // ---- NRPS: fetch the course roster (instructors) ---------------------
        case '/services/roster':
            $identity = requireSession($cache);
            if (($identity['role'] ?? '') !== 'instructor') {
                jsonExit(['error' => 'instructors only'], 403);
            }
            $nrps = $identity['nrps'] ?? null;
            if (empty($nrps['context_memberships_url'])) {
                jsonExit(['error' => 'this launch carried no NRPS endpoint'], 400);
            }

            // token dance + GET happen inside the service (client-credentials JWT
            // signed with the tool key -> bearer token -> membership request).
            $registration = $db->findRegistrationByIssuer($identity['iss']);
            $connector = new LtiServiceConnector($cache, platformHttpClient());
            $members = (new LtiNamesRolesProvisioningService($connector, $registration, $nrps))
                ->getMembers();

            jsonExit(['members' => $members]);

        default:
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'auth service — /lti/login, /lti/launch, /lti/jwks, /services/roster';
            exit;
    }
} catch (\Throwable $e) {
    if (str_starts_with($path, '/services/')) {
        jsonExit(['error' => 'service call failed', 'detail' => $e->getMessage()], 502);
    }
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
        // service endpoints used by /services/* (AGS grades / NRPS roster)
        'ags' => $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] ?? null,
        'nrps' => $claims['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice'] ?? null,
        'claims' => $claims, // full set, for the raw view in the app
    ];
}
