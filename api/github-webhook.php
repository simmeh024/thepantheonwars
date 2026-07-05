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

/**
 * Classifies a commit into one of 9 Development Dispatch categories, based on
 * keywords in the subject + body. Mirrors the heuristic used to backfill and
 * retag historical commits (see /tmp/dispatch_retag.sql in project history)
 * so new commits land in the same buckets as old ones.
 */
function pw_dispatch_tag($subject, $body = '') {
    $subject = trim($subject);
    $text = strtolower($subject . ' ' . $body);
    $subjLow = strtolower($subject);

    $has = function (...$words) use ($text) {
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) {
                return true;
            }
        }
        return false;
    };

    if (strpos($subjLow, 'fix') === 0 || preg_match('/^(feat|chore|docs|refactor|style|test)(\(.+\))?:\s*fix/', $subjLow)) {
        return 'fix';
    }
    if ($has('experimental', 'beta test', 'early access', 'prototype', 'proof of concept')) {
        return 'experimental';
    }
    if ($has('webhook', 'cpanel', 'ftp auto-deploy', 'ftp actions', 'database', 'schema', 'migration',
             'github actions', 'workflow', 'git version control', '.htaccess', 'auto-deploy',
             'deploy workflow', 'switch to cpanel', 'member system', 'session', 'csrf', 'initial commit')) {
        return 'infrastructure';
    }
    if ($has('performance', 'faster', 'optimi', 'lighthouse', 'preload', 'defer', 'lazy', 'right-size',
             'stale css', 'stale browser', 'bust stale', 'prevent stale')) {
        return 'performance';
    }
    if ($has('refactor', 'reorganize', 'clean up', 'cleanup')) {
        return 'refactor';
    }
    if ($has('lore', 'world', 'overlord', 'chapter', ' book', 'character', 'district', ' map',
             'nexus veil', 'asmecu', 'neoh', 'high hammer', 'reanium', 'maerion', 'malric', 'korrus',
             'zura', 'syn dravus', 'lysara', 'bh-4', 'kael', 'babki', 'sed ', 'geof', 'beoctica',
             'terek', 'valerium', 'vermillia', 'quiz outcome', 'writing progress')) {
        return 'lore';
    }
    if ($has('styling', 'css', 'color-code', 'redesign', 'scrollbar', 'favicon', 'lightbox', 'crop',
             'framing', 'blurry', 'drop-cap', 'watermark', 'responsive', 'zebra', 'accent', 'emphasis',
             'tooltip', 'stand out', 'hover brighten')) {
        return 'ui_ux';
    }
    if ($has('improve', 'enhance', 'add match %', 'add percentages', 'add 3 boards',
             'add popular topics', 'add formatting toolbar', 'increase', 'bump hover')) {
        return 'improvement';
    }
    return 'feature';
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
    $tag = pw_dispatch_tag($rawSubject, $body);
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
        ':author' => $author,
        ':committed_at' => $committedAt,
        ':url' => $url,
    ]);
    if ($stmt->rowCount() > 0) {
        $inserted++;
    }
}

gh_respond(['ok' => true, 'inserted' => $inserted, 'received' => count($payload['commits'])]);
