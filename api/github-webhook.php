<?php
/**
 * GitHub webhook receiver -- auto-populates Development Dispatches from every push.
 * Configured in GitHub repo settings > Webhooks (payload URL points here, content
 * type application/json, "Just the push event"). Verifies the shared secret via
 * the X-Hub-Signature-256 header before touching the database.
 */
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

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

function pw_dispatch_tag($subject) {
    $low = strtolower(trim($subject));
    if (preg_match('/^fix(\(.+\))?:/', $low) || strpos($low, 'fix ') === 0) {
        return 'fix';
    }
    if (preg_match('/^feat(\(.+\))?:/', $low)) {
        return 'feature';
    }
    if (strpos($low, 'add ') === 0 || strpos($low, 'build ') === 0 || strpos($low, 'create ') === 0) {
        return 'feature';
    }
    if (strpos($low, 'fix') === 0) {
        return 'fix';
    }
    return 'update';
}

function pw_dispatch_clean_subject($subject) {
    if (preg_match('/^(feat|fix|chore|docs|refactor|style|test)(\(.+\))?:\s*(.*)$/i', $subject, $m)) {
        return $m[3];
    }
    return $subject;
}

$db = pw_db();
$stmt = $db->prepare(
    'INSERT IGNORE INTO dispatch_entries (sha, subject, body, tag, author, committed_at, url)
     VALUES (:sha, :subject, :body, :tag, :author, :committed_at, :url)'
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
    $tag = pw_dispatch_tag($rawSubject);
    $author = !empty($commit['author']['name']) ? $commit['author']['name'] : 'Unknown';
    $timestamp = !empty($commit['timestamp']) ? $commit['timestamp'] : gmdate('c');
    $committedAt = date('Y-m-d H:i:s', strtotime($timestamp));
    $url = !empty($commit['url']) ? $commit['url'] : null;

    $stmt->execute([
        ':sha' => $commit['id'],
        ':subject' => $subject,
        ':body' => $body !== '' ? $body : null,
        ':tag' => $tag,
        ':author' => $author,
        ':committed_at' => $committedAt,
        ':url' => $url,
    ]);
    if ($stmt->rowCount() > 0) {
        $inserted++;
    }
}

gh_respond(['ok' => true, 'inserted' => $inserted, 'received' => count($payload['commits'])]);
