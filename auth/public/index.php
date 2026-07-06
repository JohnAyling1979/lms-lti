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

const ISSUER = 'https://localhost';
const APP_URL = 'https://app.lvh.me';

/**
 * A PowerNotes "project template": the graded milestones an instructor can drop
 * into a course via Deep Linking. Each becomes one LMS line item (gradebook
 * column). Together they model goal #2 — many graded assignments, one project.
 */
const PN_MILESTONES = [
    'outline' => ['label' => 'Research Outline', 'points' => 20, 'text' => 'Topic, thesis, and source list.'],
    'draft' => ['label' => 'Annotated Draft', 'points' => 30, 'text' => 'First draft with notes and citations.'],
    'final' => ['label' => 'Final Paper', 'points' => 50, 'text' => 'Completed, revised submission.'],
];

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

                $project = trim((string) ($_POST['project'] ?? '')) ?: 'PowerNotes Project';
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $project)) ?: 'pn-project';
                $chosen = (array) ($_POST['milestone'] ?? []);
                if (!$stash['acceptMultiple']) {
                    $chosen = array_slice($chosen, 0, 1);
                }

                // Each milestone -> one resource link + its own line item. All share
                // pn_project, which models goal #2: N graded LMS activities mapping
                // to ONE PowerNotes project. On a later launch the tool reads these
                // custom params to know which project/milestone it's in.
                $resources = [];
                foreach ($chosen as $key) {
                    $m = PN_MILESTONES[$key] ?? null;
                    if ($m === null) {
                        continue;
                    }
                    $points = (float) ($_POST['points'][$key] ?? $m['points']);
                    $title = $project . ' — ' . $m['label'];
                    $resources[] = Resource::new()
                        ->setTitle($title)
                        ->setText($m['text'])
                        ->setUrl('https://' . $_SERVER['HTTP_HOST'] . '/lti/launch')
                        ->setCustomParams(['pn_project' => $slug, 'pn_milestone' => $key])
                        ->setLineItem(
                            LtiLineitem::new()
                                ->setScoreMaximum($points)
                                ->setLabel($title)
                                ->setResourceId($slug . ':' . $key)
                                ->setTag('pn-milestone')
                        );
                }
                if (empty($resources)) {
                    throw new RuntimeException('pick at least one milestone');
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
    $inputType = $acceptMultiple ? 'checkbox' : 'radio';
    $multiNote = $acceptMultiple
        ? 'Pick the graded milestones to add — each becomes its own gradebook column.'
        : 'This LMS placement accepts a single item — pick one milestone.';

    $rows = '';
    foreach (PN_MILESTONES as $key => $m) {
        $k = htmlspecialchars($key, ENT_QUOTES);
        $label = htmlspecialchars($m['label'], ENT_QUOTES);
        $text = htmlspecialchars($m['text'], ENT_QUOTES);
        $pts = (int) $m['points'];
        $checked = $key === 'outline' ? 'checked' : '';
        $rows .= <<<HTML
            <label class="row">
              <input type="{$inputType}" name="milestone[]" value="{$k}" {$checked}>
              <span class="grow"><strong>{$label}</strong><br><small>{$text}</small></span>
              <span class="pts">
                <input type="number" name="points[{$k}]" value="{$pts}" min="1" max="1000"> pts
              </span>
            </label>
            HTML;
    }

    return <<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Add PowerNotes</title>
        <style>
          body { font-family: system-ui, sans-serif; color: #1c2430; margin: 0; padding: 1.5rem; background: #f4f6f9; }
          h1 { font-size: 1.25rem; margin: 0 0 .25rem; }
          p.sub { color: #667085; margin: 0 0 1rem; }
          .row { display: flex; align-items: center; gap: .75rem; background: #fff; border: 1px solid #e4e8ee;
                 border-radius: 10px; padding: .75rem 1rem; margin-bottom: .5rem; cursor: pointer; }
          .row .grow { flex: 1; }
          .row small { color: #667085; }
          .pts input { width: 4rem; font: inherit; padding: .25rem .35rem; border: 1px solid #cbd2dc; border-radius: 6px; }
          .field { margin-bottom: 1rem; }
          .field label { display: block; font-weight: 600; margin-bottom: .25rem; }
          .field input { width: 100%; font: inherit; padding: .5rem; border: 1px solid #cbd2dc; border-radius: 8px; }
          button { font: inherit; font-weight: 600; padding: .6rem 1.2rem; border: none; border-radius: 8px;
                   background: #6d28d9; color: #fff; cursor: pointer; margin-top: .5rem; }
        </style></head>
        <body>
          <h1>Add PowerNotes to {$course}</h1>
          <p class="sub">{$multiNote}</p>
          <form method="post" action="/lti/deeplink">
            <input type="hidden" name="dl" value="{$token}">
            <div class="field">
              <label for="project">Project name</label>
              <input id="project" name="project" value="Research Paper" required>
            </div>
            {$rows}
            <button type="submit">Add to course</button>
          </form>
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
