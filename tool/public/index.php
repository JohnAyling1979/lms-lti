<?php

/**
 * Minimal LTI 1.3 tool — front controller.
 *
 * Routes:
 *   GET  /              home / status
 *   GET  /lti/login     OIDC 3rd-party login initiation  (hop 1 -> redirect to platform)
 *   POST /lti/launch    receives + validates the id_token (hop 3) and dumps the claims
 *   GET  /lti/jwks      this tool's public keyset (JSON Web Key Set)
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

$db = new Database();
$cache = new Cache();
$cookie = new Cookie();

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/') ?: '/';

try {
    switch ($path) {

        // ---- Hop 1: OIDC login initiation -------------------------------------
        case '/lti/login':
            // This tool's own launch URL (browser-facing host, e.g. tool.localhost)
            $launchUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/lti/launch';

            $redirectUrl = LtiOidcLogin::new($db, $cache, $cookie)
                ->getRedirectUrl($launchUrl, $_REQUEST);

            header('Location: ' . $redirectUrl);
            exit;

        // ---- Hop 3: launch — validate the id_token and show the claims --------
        case '/lti/launch':
            $connector = new LtiServiceConnector($cache, platformHttpClient());

            // MessageFactory::create() validates state, nonce, JWT signature,
            // registration, deployment and required claims, then returns a typed
            // message (ResourceLinkRequest / DeepLinkingRequest). This is the API
            // we'll carry over to PowerNotes.
            $message = (new MessageFactory($db, $connector, $cache, $cookie))
                ->create($_REQUEST);

            renderClaims($message->getBody());
            exit;

        // ---- This tool's public keyset ---------------------------------------
        case '/lti/jwks':
            header('Content-Type: application/json');
            echo json_encode(JwksEndpoint::fromIssuer($db, ISSUER)->getPublicJwks(), JSON_PRETTY_PRINT);
            exit;

        // ---- Home ------------------------------------------------------------
        default:
            renderHome();
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

function renderClaims(array $claims): void
{
    $p = fn (string $k) => $claims[$k] ?? null;
    $L = 'https://purl.imsglobal.org/spec/lti/claim/';

    $summary = [
        'message_type'  => $p($L . 'message_type'),
        'version'       => $p($L . 'version'),
        'deployment_id' => $p($L . 'deployment_id'),
        'user (sub)'    => $p('sub'),
        'name'          => $p('name'),
        'email'         => $p('email'),
        'roles'         => $p($L . 'roles'),
        'context'       => $p($L . 'context'),
        'resource_link' => $p($L . 'resource_link'),
    ];

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>LTI launch OK</title>';
    echo '<style>body{font-family:system-ui;max-width:900px;margin:2rem auto;padding:0 1rem}
        h1{color:#0a7d28} th{text-align:left;padding:.35rem .75rem;vertical-align:top;color:#555;white-space:nowrap}
        td{padding:.35rem .75rem;vertical-align:top} table{border-collapse:collapse;background:#f6f8fa;border-radius:8px}
        pre{background:#0d1117;color:#c9d1d9;padding:1rem;border-radius:8px;overflow:auto;font-size:.8rem}
        code{background:#eef;padding:.1rem .3rem;border-radius:4px}</style>';
    echo '<h1>✅ Launch validated</h1>';
    echo '<p>The id_token signature, issuer, nonce, and deployment all checked out. Key claims:</p><table>';
    foreach ($summary as $k => $v) {
        echo '<tr><th>' . htmlspecialchars($k) . '</th><td><code>'
            . htmlspecialchars(is_scalar($v) ? (string) $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            . '</code></td></tr>';
    }
    echo '</table><h2>Full claim set</h2><pre>'
        . htmlspecialchars(json_encode($claims, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
}

function renderHome(): void
{
    $reg = json_decode(file_get_contents(__DIR__ . '/../registration.json'), true);
    $configured = $reg['client_id'] !== 'REPLACE_AFTER_MOODLE_REGISTRATION';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>LTI mock tool</title>';
    echo '<style>body{font-family:system-ui;max-width:820px;margin:2rem auto;padding:0 1rem;line-height:1.5}
        code{background:#eef;padding:.1rem .35rem;border-radius:4px}</style>';
    echo '<h1>LTI 1.3 mock tool</h1>';
    echo '<p>Registration: ' . ($configured ? '✅ configured' : '⚠️ <b>not yet configured</b> — fill client_id/deployment_id in <code>registration.json</code> after registering in Moodle') . '</p>';
    echo '<h3>Endpoints to register in Moodle</h3><ul>'
        . '<li>Tool / Redirection URL: <code>https://tool.localhost/lti/launch</code></li>'
        . '<li>Initiate login URL: <code>https://tool.localhost/lti/login</code></li>'
        . '<li>Public keyset URL: <code>https://tool.localhost/lti/jwks</code> (Moodle fetches this server-side; see note in README)</li>'
        . '</ul>';
    echo '<p><a href="/lti/jwks">View this tool\'s JWKS →</a></p>';
}
