<?php

/**
 * auth.lvh.me — the LTI service. Validates launches, establishes the session,
 * and makes LTI Advantage service calls (NRPS/AGS) on the tool's behalf — it's
 * the only service holding the tool's private signing key.
 *
 *   GET  /lti/login          OIDC 3rd-party login initiation
 *   POST /lti/launch         validate the id_token, create session, set cookie, -> app
 *   GET  /lti/jwks           this tool's public keyset
 *   POST /lti/deeplink       Deep Linking — pick content, return signed line items
 *   GET  /services/roster    NRPS — course membership (instructors)
 *   GET  /services/lineitems AGS — list the tool's line items (instructors)
 *   GET  /services/results   AGS — read existing grades for a line item (instructors)
 *   GET  /services/submissions  learner submissions for a placement (instructors)
 *   GET  /services/needsgrading ungraded-submission counts per assignment (instructors)
 *   POST /services/grade     AGS — push a score to the gradebook (instructors)
 *   GET  /services/submission the learner's saved work for this placement
 *   POST /services/submit    save work to the tool + mark the LMS activity Submitted
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Cache;
use App\Cookie;
use App\Database;
use App\ToolDb;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Packback\Lti1p3\DeepLinkResources\Resource;
use Packback\Lti1p3\Factories\MessageFactory;
use Packback\Lti1p3\JwksEndpoint;
use Packback\Lti1p3\LtiAssignmentsGradesService;
use Packback\Lti1p3\LtiDeepLink;
use Packback\Lti1p3\LtiGrade;
use Packback\Lti1p3\LtiLineitem;
use Packback\Lti1p3\LtiNamesRolesProvisioningService;
use Packback\Lti1p3\LtiOidcLogin;
use Packback\Lti1p3\LtiServiceConnector;
use Packback\Lti1p3\Messages\DeepLinkingRequest;

// Environment-driven, with local-dev defaults. In the cloud these become the
// real *.powernotes.com hosts (see docker-compose.prod.yml).
define('ISSUER', getenv('LTI_ISSUER') ?: 'https://localhost');    // = Moodle's SITE_URL / iss
define('APP_URL', getenv('APP_URL') ?: 'https://app.lvh.me');     // SPA origin (redirect target + CORS)
define('PLATFORM_HOST', getenv('PLATFORM_HOST') ?: 'localhost');  // Moodle's public host (split-horizon shim)
define('COOKIE_DOMAIN', getenv('COOKIE_DOMAIN') ?: 'lvh.me');     // shared parent for the session cookie

/** URL/id-safe slug from a human label (e.g. "Annotated Draft" -> "annotated-draft"). */
function pnSlug(string $s): string
{
    return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s)), '-');
}

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
        if ($uri->getHost() === PLATFORM_HOST) {
            $r = $r->withUri($uri->withScheme('http')->withHost('moodle')->withPort(8080));
        }

        return $r->withHeader('Host', PLATFORM_HOST)->withHeader('X-Forwarded-Proto', 'https');
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
        // One login endpoint serves both message types. The platform tells us
        // where to return via target_link_uri: a resource-link launch points at
        // /lti/launch, a Deep Linking launch at /lti/deeplink. Both must be
        // registered redirect URIs on the tool in the LMS.
        case '/lti/login':
            $target = $_REQUEST['target_link_uri'] ?? ('https://' . $_SERVER['HTTP_HOST'] . '/lti/launch');
            $redirectUrl = LtiOidcLogin::new($db, $cache, $cookie)
                ->getRedirectUrl($target, $_REQUEST);
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
                'domain' => COOKIE_DOMAIN,
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            header('Location: ' . APP_URL);
            exit;

        // ---- This tool's public keyset ---------------------------------------
        // Just publishes the tool's public key(s) from its own keypair — it does
        // NOT depend on any platform registration (a platform fetches this DURING
        // registration, before a row exists). The kid must match what the tool
        // signs with (deep-linking responses / client assertions).
        case '/lti/jwks':
            header('Content-Type: application/json');
            $kid = getenv('TOOL_KID') ?: 'lms-lti-tool-1';
            $privateKey = file_get_contents(__DIR__ . '/../keys/private.key');
            echo json_encode(JwksEndpoint::new([$kid => $privateKey])->getPublicJwks(), JSON_UNESCAPED_SLASHES);
            exit;

        // ---- Deep Linking: instructor picks content, we return signed items --
        // Two hops, both landing here:
        //   A. The DL launch from the LMS (id_token). We validate it, stash the
        //      return_url + deployment, and render a content-selection form.
        //   B. That form's submit (our own `dl` token + chosen milestones). We
        //      build one ltiResourceLink per milestone — each carrying a lineItem
        //      so the LMS creates a gradable activity + gradebook column — sign
        //      the DeepLinkingResponse JWT, and auto-POST it back to the LMS.
        case '/lti/deeplink':
            // Hop B: the content-selection form came back to us.
            if (isset($_POST['dl'])) {
                $stash = $cache->takeDeepLink((string) $_POST['dl']);
                if ($stash === null) {
                    throw new RuntimeException('deep-link session expired — relaunch "Select content"');
                }
                $registration = $db->findRegistrationByIssuer($stash['iss']);
                $deepLink = new LtiDeepLink($registration, $stash['deploymentId'], $stash['settings']);

                $group = trim((string) ($_POST['group'] ?? '')) ?: 'PowerNotes Project';
                $slug = pnSlug($group) ?: 'pn-project';
                $assignments = json_decode((string) ($_POST['assignments'] ?? '[]'), true) ?: [];
                if (!$stash['acceptMultiple']) {
                    $assignments = array_slice($assignments, 0, 1);
                }

                // Each authored assignment -> one resource link + (if it has points)
                // its own line item. All share pn_project, modelling goal #2: N graded
                // LMS activities mapping to ONE PowerNotes project. The instructor built
                // these in the tool wizard; the LMS just places them. On a later launch
                // the tool reads these custom params to know which project/item it's in.
                $resources = [];
                foreach ($assignments as $i => $a) {
                    $name = trim((string) ($a['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $points = (float) ($a['points'] ?? 0);
                    $text = trim((string) ($a['text'] ?? ''));
                    $key = pnSlug($name) ?: ('item-' . $i);
                    $title = $group . ' — ' . $name;

                    $resource = Resource::new()
                        ->setTitle($title)
                        ->setText($text !== '' ? $text : null)
                        ->setUrl('https://' . $_SERVER['HTTP_HOST'] . '/lti/launch')
                        ->setCustomParams(['pn_project' => $slug, 'pn_milestone' => $key]);
                    if ($points > 0) {
                        $resource->setLineItem(
                            LtiLineitem::new()
                                ->setScoreMaximum($points)
                                ->setLabel($title)
                                ->setResourceId($slug . ':' . $key)
                                ->setTag('pn-milestone')
                        );
                    }
                    $resources[] = $resource;
                }
                if (empty($resources)) {
                    throw new RuntimeException('add at least one assignment');
                }

                $jwt = $deepLink->getResponseJwt($resources);
                echo deepLinkAutoPost($deepLink->returnUrl(), $jwt, count($resources));
                exit;
            }

            // Hop A: the DL launch itself. Validate, confirm it's a DL request
            // from an instructor, stash what hop B needs, render the picker.
            $connector = new LtiServiceConnector($cache, platformHttpClient());
            $message = (new MessageFactory($db, $connector, $cache, $cookie))->create($_REQUEST);
            if (!$message instanceof DeepLinkingRequest) {
                throw new RuntimeException('not a Deep Linking request');
            }
            $identity = buildIdentity($message->getBody());
            if ($identity['role'] !== 'instructor') {
                throw new RuntimeException('only instructors can add content');
            }

            $deepLink = $message->getDeepLink();
            $token = bin2hex(random_bytes(16));
            $cache->stashDeepLink($token, [
                'iss' => $identity['iss'],
                'deploymentId' => $identity['deploymentId'],
                'settings' => $deepLink->settings(),
                'acceptMultiple' => $deepLink->canAcceptMultiple(),
            ]);

            header('Content-Type: text/html; charset=utf-8');
            echo deepLinkPicker(
                $token,
                $identity['context']['title'] ?? 'this course',
                $deepLink->canAcceptMultiple()
            );
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

        // ---- AGS: list the tool's OWN line items (assignments) ---------------
        case '/services/lineitems':
            $identity = requireSession($cache);
            if (($identity['role'] ?? '') !== 'instructor') {
                jsonExit(['error' => 'instructors only'], 403);
            }
            $ags = $identity['ags'] ?? null;
            if (empty($ags['lineitems'])) {
                jsonExit(['lineitems' => []]); // launch carried no AGS endpoint
            }
            $registration = $db->findRegistrationByIssuer($identity['iss']);
            $connector = new LtiServiceConnector($cache, platformHttpClient());
            $items = (new LtiAssignmentsGradesService($connector, $registration, $ags))->getLineItems();
            jsonExit(['lineitems' => array_map(fn ($li) => [
                'id' => $li['id'] ?? null,
                'label' => $li['label'] ?? null,
                'scoreMaximum' => $li['scoreMaximum'] ?? null,
                // links the line item to its resource link -> our submissions.
                'resourceLinkId' => $li['resourceLinkId'] ?? null,
            ], $items)]);

        // ---- Instructor "needs grading" queue (the notice Moodle won't send) -
        // Per assignment: submissions that have no grade yet. It's a tool concept
        // (the LMS has no submission event), so the tool computes it — crossing
        // its own submissions against AGS grades. O(N) AGS calls over line items.
        case '/services/needsgrading':
            $identity = requireSession($cache);
            if (($identity['role'] ?? '') !== 'instructor') {
                jsonExit(['error' => 'instructors only'], 403);
            }
            $ags = $identity['ags'] ?? null;
            if (empty($ags['lineitems'])) {
                jsonExit(['items' => [], 'total' => 0]);
            }
            $registration = $db->findRegistrationByIssuer($identity['iss']);
            $connector = new LtiServiceConnector($cache, platformHttpClient());
            $svc = new LtiAssignmentsGradesService($connector, $registration, $ags);
            $toolDb = new ToolDb();

            $items = [];
            $total = 0;
            foreach ($svc->getLineItems() as $li) {
                $rl = $li['resourceLinkId'] ?? null;
                if ($rl === null) {
                    continue;
                }
                $subs = $toolDb->getSubmissionsByResourceLink((string) $rl);
                if (empty($subs)) {
                    continue;
                }
                // who already has an actual score on this line item
                $graded = [];
                foreach ($svc->getGrades(LtiLineitem::new()->setId($li['id'])) as $r) {
                    if (($r['resultScore'] ?? null) !== null) {
                        $graded[(string) ($r['userId'] ?? '')] = true;
                    }
                }
                $pending = 0;
                foreach ($subs as $s) {
                    if (empty($graded[(string) $s['userId']])) {
                        $pending++;
                    }
                }
                if ($pending > 0) {
                    $items[] = [
                        'lineitem' => $li['id'] ?? null,
                        'label' => $li['label'] ?? null,
                        'resourceLinkId' => (string) $rl,
                        'needsGrading' => $pending,
                    ];
                    $total += $pending;
                }
            }

            jsonExit(['items' => $items, 'total' => $total]);

        // ---- Instructor view: every learner's submitted work for a placement -
        // Keyed by the line item's resourceLinkId (from getLineItems) = the
        // resource_link_id we stored on submit. The work lives in the tool's DB,
        // never the LMS, so this is the ONLY place a teacher can read it.
        case '/services/submissions':
            $identity = requireSession($cache);
            if (($identity['role'] ?? '') !== 'instructor') {
                jsonExit(['error' => 'instructors only'], 403);
            }
            $rl = (string) ($_GET['resourceLinkId'] ?? '');
            if ($rl === '') {
                jsonExit(['submissions' => []]);
            }
            jsonExit(['submissions' => (new ToolDb())->getSubmissionsByResourceLink($rl)]);

        // ---- AGS: read existing grades for a line item (instructors) ---------
        // The read side of AGS (result.readonly scope): who already has a score,
        // so the UI can show "already graded" — including grades entered directly
        // in Moodle, not just ones this tool posted.
        case '/services/results':
            $identity = requireSession($cache);
            if (($identity['role'] ?? '') !== 'instructor') {
                jsonExit(['error' => 'instructors only'], 403);
            }
            $ags = $identity['ags'] ?? null;
            $lineitemUrl = (string) ($_GET['lineitem'] ?? '');
            if (empty($ags) || $lineitemUrl === '') {
                jsonExit(['results' => []]);
            }
            $registration = $db->findRegistrationByIssuer($identity['iss']);
            $connector = new LtiServiceConnector($cache, platformHttpClient());
            $results = (new LtiAssignmentsGradesService($connector, $registration, $ags))
                ->getGrades(LtiLineitem::new()->setId($lineitemUrl));
            jsonExit(['results' => array_map(fn ($r) => [
                'userId' => $r['userId'] ?? null,
                'resultScore' => $r['resultScore'] ?? null,
                'resultMaximum' => $r['resultMaximum'] ?? null,
            ], $results)]);

        // ---- AGS: push a score to an EXISTING line item (instructors) --------
        case '/services/grade':
            $identity = requireSession($cache);
            if (($identity['role'] ?? '') !== 'instructor') {
                jsonExit(['error' => 'instructors only'], 403);
            }
            $ags = $identity['ags'] ?? null;
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $lineitemUrl = (string) ($body['lineitem'] ?? '');
            $userId = (string) ($body['userId'] ?? '');
            $score = (float) ($body['score'] ?? 0);
            $scoreMaximum = (float) ($body['scoreMaximum'] ?? 100);
            if ($lineitemUrl === '' || $userId === '') {
                jsonExit(['error' => 'lineitem and userId are required'], 400);
            }

            $registration = $db->findRegistrationByIssuer($identity['iss']);
            $connector = new LtiServiceConnector($cache, platformHttpClient());

            // Post to the EXISTING line item (a real assignment, created via deep
            // linking). No hardcoded column — the tool grades an item it owns.
            (new LtiAssignmentsGradesService($connector, $registration, $ags))->putGrade(
                LtiGrade::new()
                    ->setScoreGiven($score)
                    ->setScoreMaximum($scoreMaximum)
                    ->setActivityProgress('Completed')
                    ->setGradingProgress('FullyGraded')
                    ->setTimestamp(date('c'))
                    ->setUserId($userId),
                LtiLineitem::new()->setId($lineitemUrl)
            );

            jsonExit(['ok' => true, 'lineitem' => $lineitemUrl, 'userId' => $userId, 'score' => $score]);

        // ---- The learner's saved work + their OWN grade for THIS placement ---
        // On load the learner UI needs both: the work (from our DB) and whether
        // it's been graded yet (AGS Results, filtered to their sub) — so it can
        // show the grade and stop them resubmitting over it.
        case '/services/submission':
            $identity = requireSession($cache);
            $submission = (new ToolDb())->getSubmission(submissionKey($identity));

            $grade = null;
            $ags = $identity['ags'] ?? null;
            $lineitemUrl = $ags['lineitem'] ?? null;
            if (!empty($lineitemUrl)) {
                $registration = $db->findRegistrationByIssuer($identity['iss']);
                $connector = new LtiServiceConnector($cache, platformHttpClient());
                $results = (new LtiAssignmentsGradesService($connector, $registration, $ags))
                    ->getGrades(LtiLineitem::new()->setId($lineitemUrl), $identity['sub']);
                $r = $results[0] ?? null;
                // a bare "Submitted" status has no resultScore — only treat an
                // actual score as "graded".
                if ($r !== null && ($r['resultScore'] ?? null) !== null) {
                    $grade = [
                        'resultScore' => $r['resultScore'],
                        'resultMaximum' => $r['resultMaximum'] ?? null,
                    ];
                }
            }

            jsonExit(['submission' => $submission, 'grade' => $grade]);

        // ---- AGS: a learner turns in their work for THIS placement -----------
        // The work itself lives in the tool (PowerNotes) — LTI carries only status.
        // We (1) save the content to the tool's own store so it survives reloads,
        // then (2) mark the LMS activity Submitted / PendingManual (NO score) via
        // the specific line item the launch handed us. The score stays the
        // instructor's job; the student never sets a number.
        case '/services/submit':
            $identity = requireSession($cache);
            $ags = $identity['ags'] ?? null;
            $lineitemUrl = $ags['lineitem'] ?? null; // specific to this resource link
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $content = trim((string) ($body['content'] ?? ''));
            $submittedAt = date('c');

            // (1) durable in the tool's DB — this is what makes submission re-readable.
            (new ToolDb())->putSubmission(submissionKey($identity), $identity, [
                'content' => $content,
                'submittedAt' => $submittedAt,
            ]);

            // (2) status to the LMS (only if this placement is gradable).
            if (!empty($lineitemUrl)) {
                $registration = $db->findRegistrationByIssuer($identity['iss']);
                $connector = new LtiServiceConnector($cache, platformHttpClient());
                (new LtiAssignmentsGradesService($connector, $registration, $ags))->putGrade(
                    LtiGrade::new()
                        ->setActivityProgress('Submitted')
                        ->setGradingProgress('PendingManual')
                        ->setTimestamp($submittedAt)
                        ->setUserId($identity['sub']),
                    LtiLineitem::new()->setId($lineitemUrl)
                );
            }

            jsonExit(['ok' => true, 'submittedAt' => $submittedAt]);

        default:
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'auth service — /lti/login, /lti/launch, /lti/jwks, /lti/deeplink, /services/*';
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

/** Stable key for a learner's submission: one per (issuer, placement, learner). */
function submissionKey(array $identity): string
{
    return hash('sha256', implode('|', [
        $identity['iss'] ?? '',
        $identity['resourceLink']['id'] ?? '',
        $identity['sub'] ?? '',
    ]));
}

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

/**
 * The content-selection UI (Deep Linking hop A). Renders inside the LMS's modal
 * iframe, so it's a plain self-contained page. Posts back to /lti/deeplink with
 * the opaque `dl` token that lets hop B re-sign against the original launch.
 */
function deepLinkPicker(string $token, string $courseTitle, bool $acceptMultiple): string
{
    $course = htmlspecialchars($courseTitle, ENT_QUOTES);
    $token = htmlspecialchars($token, ENT_QUOTES);
    $multiple = $acceptMultiple ? 'true' : 'false';

    return <<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Add PowerNotes</title>
        <style>
          body { font-family: system-ui, sans-serif; color: #1c2430; margin: 0; padding: 1.5rem; background: #f4f6f9; }
          h1 { font-size: 1.2rem; margin: 0 0 .25rem; }
          .sub { color: #667085; margin: 0 0 1rem; font-size: .9rem; }
          .steps { display: flex; gap: .4rem; margin-bottom: 1rem; font-size: .78rem; }
          .steps span { flex: 1; text-align: center; padding: .4rem; border-radius: 8px; background: #eef1f6; color: #667085; font-weight: 600; }
          .steps span.on { background: #6d28d9; color: #fff; }
          .panel { background: #fff; border: 1px solid #e4e8ee; border-radius: 12px; padding: 1.25rem; }
          .hidden { display: none; }
          label.lbl { display: block; font-weight: 600; margin-bottom: .25rem; }
          input, textarea { font: inherit; padding: .5rem; border: 1px solid #cbd2dc; border-radius: 8px; width: 100%; box-sizing: border-box; }
          .row { display: flex; gap: .6rem; align-items: flex-start; background: #f8fafc; border: 1px solid #e4e8ee;
                 border-radius: 10px; padding: .6rem .75rem; margin-bottom: .5rem; }
          .row .grow { flex: 1; display: grid; gap: .35rem; }
          .row .pts { width: 4rem; flex: none; text-align: center; color: #667085; font-size: .72rem; }
          .row .pts input { text-align: center; padding: .3rem; }
          .row .rm { flex: none; background: none; border: none; color: #b91c1c; font-size: 1.2rem; cursor: pointer; padding: 0 .25rem; }
          .actions { display: flex; justify-content: space-between; margin-top: 1rem; }
          button { font: inherit; font-weight: 600; padding: .55rem 1.1rem; border: 1px solid #cbd2dc; border-radius: 8px; background: #fff; cursor: pointer; }
          button.primary { background: #6d28d9; color: #fff; border-color: #6d28d9; }
          button.ghost { background: none; border: 1px dashed #cbd2dc; color: #6d28d9; width: 100%; margin-top: .25rem; }
          ul.rev { list-style: none; padding: 0; margin: .5rem 0 0; }
          ul.rev li { background: #f8fafc; border: 1px solid #e4e8ee; border-radius: 8px; padding: .5rem .75rem; margin-bottom: .4rem; }
          ul.rev small { color: #667085; }
        </style></head>
        <body>
          <div class="steps">
            <span class="on" id="d1">1 · Name</span>
            <span id="d2">2 · Assignments</span>
            <span id="d3">3 · Review</span>
          </div>

          <section class="panel" id="p1">
            <h1>Name this assignment group</h1>
            <p class="sub">Adding to {$course}. The group name prefixes each assignment below.</p>
            <label class="lbl" for="group">Group name</label>
            <input id="group" value="Research Paper" placeholder="e.g. Research Paper">
            <div class="actions"><span></span><button class="primary" onclick="toStep(2)">Next →</button></div>
          </section>

          <section class="panel hidden" id="p2">
            <h1>Add assignments</h1>
            <p class="sub" id="p2sub"></p>
            <div id="rows"></div>
            <button class="ghost" id="addBtn" onclick="addRow()">+ Add assignment</button>
            <div class="actions">
              <button onclick="toStep(1)">← Back</button>
              <button class="primary" onclick="toStep(3)">Review →</button>
            </div>
          </section>

          <section class="panel hidden" id="p3">
            <h1>Review &amp; link</h1>
            <div id="review"></div>
            <form id="dlForm" method="post" action="/lti/deeplink">
              <input type="hidden" name="dl" value="{$token}">
              <input type="hidden" name="group" id="fGroup">
              <input type="hidden" name="assignments" id="fAssignments">
            </form>
            <div class="actions">
              <button onclick="toStep(2)">← Back</button>
              <button class="primary" onclick="submitDl()">Link to course</button>
            </div>
          </section>

          <script>
            var acceptMultiple = {$multiple};
            var group = 'Research Paper';
            var items = [{name: '', points: 20, text: ''}];

            function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

            function readRows() {
              var out = [];
              document.querySelectorAll('#rows .row').forEach(function (r) {
                out.push({ name: r.querySelector('.a-name').value, points: r.querySelector('.a-points').value, text: r.querySelector('.a-text').value });
              });
              if (out.length) items = out;
            }

            function renderRows() {
              var html = '';
              items.forEach(function (it, i) {
                html += '<div class="row"><span class="grow">'
                  + '<input class="a-name" placeholder="Assignment name" value="' + esc(it.name) + '">'
                  + '<textarea class="a-text" rows="2" placeholder="Instructions (optional)">' + esc(it.text) + '</textarea>'
                  + '</span><span class="pts"><input class="a-points" type="number" min="0" max="1000" value="' + esc(it.points) + '">points</span>'
                  + (items.length > 1 ? '<button class="rm" title="remove" onclick="removeRow(' + i + ')">&times;</button>' : '')
                  + '</div>';
              });
              document.getElementById('rows').innerHTML = html;
              document.getElementById('addBtn').style.display = acceptMultiple ? '' : 'none';
              document.getElementById('p2sub').textContent = acceptMultiple
                ? 'Each becomes its own gradebook column. Set points to 0 for an ungraded activity.'
                : 'This placement accepts a single item — add one assignment.';
            }

            function addRow() { readRows(); items.push({ name: '', points: 20, text: '' }); renderRows(); }
            function removeRow(i) { readRows(); items.splice(i, 1); if (!items.length) items = [{ name: '', points: 20, text: '' }]; renderRows(); }

            function pick() {
              readRows();
              var v = items.filter(function (it) { return String(it.name).trim() !== ''; });
              return acceptMultiple ? v : v.slice(0, 1);
            }

            function toStep(n) {
              readRows();
              if (n >= 2) group = (document.getElementById('group').value || '').trim() || 'PowerNotes Project';
              if (n === 2) renderRows();
              if (n === 3) {
                var v = pick();
                if (!v.length) { alert('Add at least one assignment with a name.'); return; }
                var html = '<p class="sub">Group: <strong>' + esc(group) + '</strong></p><ul class="rev">';
                v.forEach(function (it) {
                  var pts = Number(it.points) || 0;
                  html += '<li><strong>' + esc(group) + ' — ' + esc(String(it.name).trim()) + '</strong> · '
                    + (pts > 0 ? pts + ' pts' : 'ungraded')
                    + (String(it.text).trim() ? '<br><small>' + esc(String(it.text).trim()) + '</small>' : '') + '</li>';
                });
                document.getElementById('review').innerHTML = html + '</ul>';
              }
              ['p1', 'p2', 'p3'].forEach(function (id, i) { document.getElementById(id).classList.toggle('hidden', (i + 1) !== n); });
              [1, 2, 3].forEach(function (s) { document.getElementById('d' + s).classList.toggle('on', s <= n); });
            }

            function submitDl() {
              var v = pick().map(function (it) { return { name: String(it.name).trim(), points: Number(it.points) || 0, text: String(it.text).trim() }; });
              if (!v.length) { alert('Add at least one assignment.'); toStep(2); return; }
              document.getElementById('fGroup').value = group;
              document.getElementById('fAssignments').value = JSON.stringify(v);
              document.getElementById('dlForm').submit();
            }

            renderRows();
          </script>
        </body></html>
        HTML;
}

/**
 * Deep Linking hop B result: a self-submitting form that POSTs the signed
 * DeepLinkingResponse JWT back to the LMS's deep_link_return_url. The LMS
 * verifies it against our JWKS and creates the activity/activities.
 */
function deepLinkAutoPost(string $returnUrl, string $jwt, int $count): string
{
    $url = htmlspecialchars($returnUrl, ENT_QUOTES);
    $token = htmlspecialchars($jwt, ENT_QUOTES);
    $noun = $count === 1 ? '1 item' : $count . ' items';

    return <<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8"><title>Adding…</title></head>
        <body style="font-family:system-ui;padding:2rem;color:#667085">
          Returning {$noun} to your LMS…
          <form id="dl" method="post" action="{$url}">
            <input type="hidden" name="JWT" value="{$token}">
          </form>
          <script>document.getElementById('dl').submit();</script>
        </body></html>
        HTML;
}
