<?php
/**
 * GitHub webhook receiver -- auto-populates Development Dispatches from every push.
 * Configured in GitHub repo settings > Webhooks (payload URL points here, content
 * type application/json, "Just the push event"). Verifies the shared secret via
 * the X-Hub-Signature-256 header before touching the database.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/dispatch-helpers.php';
require_once __DIR__ . '/dispatch-diff-context.php';
require_once __DIR__ . '/dispatch-translation-drafts.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

function gh_respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gh_respond(['ok' => false, 'error' => 'POST required'], 405);
}

if (!defined('GITHUB_WEBHOOK_SECRET') || GITHUB_WEBHOOK_SECRET === '') {
    gh_respond(['ok' => false, 'error' => 'Webhook secret not configured'], 500);
}

$rawBody = file_get_contents('php://input');
$signatureHeader = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';

if ($signatureHeader === '') {
    gh_respond(['ok' => false, 'error' => 'Missing signature'], 401);
}

$expected = 'sha256=' . hash_hmac('sha256', $rawBody, GITHUB_WEBHOOK_SECRET);
if (!hash_equals($expected, $signatureHeader)) {
    gh_respond(['ok' => false, 'error' => 'Invalid signature'], 401);
}

// Record that GitHub reached us -- this is the "Webhook Delivery" signal on
// the System Status page, independent of whether the repo itself is
// reachable from our side. Runs for every authenticated call (ping or push)
// since either one proves delivery is working; failures here shouldn't ever
// block the actual webhook processing below, so they're swallowed silently.
try {
    $settingsDb = pw_db();
    $settingsStmt = $settingsDb->prepare(
        "INSERT INTO app_settings (`key`, value) VALUES ('last_webhook_received_at', :v)
         ON DUPLICATE KEY UPDATE value = :v2, updated_at = CURRENT_TIMESTAMP"
    );
    $nowUtc = gmdate('Y-m-d H:i:s');
    $settingsStmt->execute([':v' => $nowUtc, ':v2' => $nowUtc]);
} catch (Exception $e) {
    // app_settings may not exist yet on older deployments -- Webhook Delivery
    // just shows "Not tracked yet" on the status page until it's migrated in.
}

$event = isset($_SERVER['HTTP_X_GITHUB_EVENT']) ? $_SERVER['HTTP_X_GITHUB_EVENT'] : '';

if ($event === 'ping') {
    gh_respond(['ok' => true, 'message' => 'pong']);
}

if ($event !== 'push') {
    gh_respond(['ok' => true, 'message' => 'Ignored event: ' . $event]);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload) || empty($payload['commits']) || !is_array($payload['commits'])) {
    gh_respond(['ok' => true, 'message' => 'No commits in payload']);
}

$db = pw_db();
$stmt = $db->prepare(
    'INSERT IGNORE INTO dispatch_entries (sha, subject, body, tag, category_confidence, category_source, author, committed_at, url)
     VALUES (:sha, :subject, :body, :tag, :category_confidence, :category_source, :author, :committed_at, :url)'
);

$inserted = 0;
foreach ($payload['commits'] as $commit) {
    if (empty($commit['id']) || empty($commit['message'])) {
        continue;
    }
    $fullMessage = trim($commit['message']);
    $lines = preg_split('/\r?\n/', $fullMessage, 2);
    $rawSubject = trim($lines[0]);
    $body = isset($lines[1]) ? trim($lines[1]) : '';
    $subject = pw_dispatch_clean_subject($rawSubject);
    // The push payload already lists changed files for free (no extra GitHub
    // API call), so this is the one path that can always feed real
    // diff-context into categorization rather than text signals alone.
    $paths = array_merge(
        is_array($commit['added'] ?? null) ? $commit['added'] : [],
        is_array($commit['modified'] ?? null) ? $commit['modified'] : [],
        is_array($commit['removed'] ?? null) ? $commit['removed'] : []
    );
    $diffContext = pw_dispatch_diff_context_from_paths($paths);
    $categorized = pw_dispatch_categorize($rawSubject, $body, $diffContext);
    $tag = $categorized['tag'];
    $author = !empty($commit['author']['name']) ? $commit['author']['name'] : 'Unknown';
    $timestamp = !empty($commit['timestamp']) ? $commit['timestamp'] : gmdate('c');
    // Keep the commit author's literal local wall-clock time (matching the
    // git-log-derived backfill data) instead of converting through the
    // server's default PHP timezone, which would shift it and break sort
    // order against the backfilled rows.
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $timestamp, $tsMatch)) {
        $committedAt = $tsMatch[1] . ' ' . $tsMatch[2];
    } else {
        $committedAt = date('Y-m-d H:i:s', strtotime($timestamp));
    }
    $url = !empty($commit['url']) ? $commit['url'] : null;

    $stmt->execute([
        ':sha' => $commit['id'],
        ':subject' => $subject,
        ':body' => $body !== '' ? $body : null,
        ':tag' => $tag,
        ':category_confidence' => $categorized['confidence'],
        ':category_source' => 'auto',
        ':author' => $author,
        ':committed_at' => $committedAt,
        ':url' => $url,
    ]);
    if ($stmt->rowCount() > 0) {
        $inserted++;
        $dispatchId = (int)$db->lastInsertId();
        pw_store_dispatch_diff_context($db, $dispatchId, $diffContext);
        try {
            pw_create_dispatch_translation_draft($db, $dispatchId);
        } catch (PDOException $e) {
            // The migration can be applied after deployment. Never reject a
            // verified GitHub delivery merely because the optional draft table
            // is not present yet.
        }
    }
}

gh_respond(['ok' => true, 'inserted' => $inserted, 'received' => count($payload['commits'])]);
