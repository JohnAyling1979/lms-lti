<?php

/**
 * api.lvh.me — the session API. Reads the session the auth service established.
 *
 *   GET  /api/me      -> { user }   (or 401 if no/invalid session)
 *   POST /api/logout  -> { ok }     (clears session + cookie)
 *
 * This is a different ORIGIN from the UI (app.lvh.me), so it answers with CORS,
 * with credentials, so the browser sends the Domain=lvh.me session cookie.
 */

declare(strict_types=1);

require __DIR__ . '/../src/SessionStore.php';

use App\SessionStore;

// --- CORS: allow the UI origin to make credentialed requests ---------------
$appUrl = getenv('APP_URL') ?: 'https://app.lvh.me';
header('Access-Control-Allow-Origin: ' . $appUrl); // exact origin, never '*'
header('Access-Control-Allow-Credentials: true');
header('Vary: Origin');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

function json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
}

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/') ?: '/';
$store = new SessionStore();
$sid = $_COOKIE['pn_session'] ?? '';

switch ($path) {
    case '/api/me':
        $identity = $store->get($sid);
        $identity === null
            ? json(['error' => 'unauthenticated'], 401)
            : json(['user' => $identity]);
        exit;

    case '/api/logout':
        $store->delete($sid);
        setcookie('pn_session', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => getenv('COOKIE_DOMAIN') ?: 'lvh.me',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        json(['ok' => true]);
        exit;

    default:
        json(['error' => 'not found'], 404);
        exit;
}
