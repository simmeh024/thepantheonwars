<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$dispatchId = isset($input['dispatch_id']) ? (int)$input['dispatch_id'] : 0;
$type = isset($input['reaction_type']) ? trim($input['reaction_type']) : '';
$validTypes = ['like', 'dislike'];

if ($dispatchId <= 0) {
    pw_error('Missing dispatch id.');
}
if (!in_array($type, $validTypes, true)) {
    pw_error('Unknown reaction type.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id FROM dispatch_entries WHERE id = ?');
$stmt->execute([$dispatchId]);
if (!$stmt->fetch()) {
    pw_error('That dispatch no longer exists.', 404);
}

$stmt = $db->prepare('SELECT reaction_type FROM dispatch_reactions WHERE dispatch_id = ? AND user_id = ?');
$stmt->execute([$dispatchId, $user['id']]);
$existing = $stmt->fetch();

if ($existing && $existing['reaction_type'] === $type) {
    // Toggle off -- clicking the same reaction again removes it.
    $stmt = $db->prepare('DELETE FROM dispatch_reactions WHERE dispatch_id = ? AND user_id = ?');
    $stmt->execute([$dispatchId, $user['id']]);
    $myReaction = null;
} elseif ($existing) {
    $stmt = $db->prepare('UPDATE dispatch_reactions SET reaction_type = ?, created_at = CURRENT_TIMESTAMP WHERE dispatch_id = ? AND user_id = ?');
    $stmt->execute([$type, $dispatchId, $user['id']]);
    $myReaction = $type;
} else {
    $stmt = $db->prepare('INSERT INTO dispatch_reactions (dispatch_id, user_id, reaction_type) VALUES (?, ?, ?)');
    $stmt->execute([$dispatchId, $user['id'], $type]);
    $myReaction = $type;
}

$countStmt = $db->prepare('SELECT reaction_type, COUNT(*) AS cnt FROM dispatch_reactions WHERE dispatch_id = ? GROUP BY reaction_type');
$countStmt->execute([$dispatchId]);
$counts = ['like' => 0, 'dislike' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $counts[$row['reaction_type']] = (int)$row['cnt'];
}

pw_json(['ok' => true, 'reactions' => $counts, 'myReaction' => $myReaction]);
