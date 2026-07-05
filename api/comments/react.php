<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$commentId = isset($input['comment_id']) ? (int)$input['comment_id'] : 0;
$type = isset($input['reaction_type']) ? trim($input['reaction_type']) : '';
$validTypes = ['shard', 'ward', 'ember'];

if ($commentId <= 0) {
    pw_error('Missing comment id.');
}
if (!in_array($type, $validTypes, true)) {
    pw_error('Unknown reaction type.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id FROM comments WHERE id = ? AND is_deleted = 0');
$stmt->execute([$commentId]);
if (!$stmt->fetch()) {
    pw_error('That message no longer exists.', 404);
}

$stmt = $db->prepare('SELECT reaction_type FROM comment_reactions WHERE comment_id = ? AND user_id = ?');
$stmt->execute([$commentId, $user['id']]);
$existing = $stmt->fetch();

if ($existing && $existing['reaction_type'] === $type) {
    // Toggle off — clicking the same reaction again removes it.
    $stmt = $db->prepare('DELETE FROM comment_reactions WHERE comment_id = ? AND user_id = ?');
    $stmt->execute([$commentId, $user['id']]);
    $myReaction = null;
} elseif ($existing) {
    $stmt = $db->prepare('UPDATE comment_reactions SET reaction_type = ?, created_at = CURRENT_TIMESTAMP WHERE comment_id = ? AND user_id = ?');
    $stmt->execute([$type, $commentId, $user['id']]);
    $myReaction = $type;
} else {
    $stmt = $db->prepare('INSERT INTO comment_reactions (comment_id, user_id, reaction_type) VALUES (?, ?, ?)');
    $stmt->execute([$commentId, $user['id'], $type]);
    $myReaction = $type;
}

$countStmt = $db->prepare('SELECT reaction_type, COUNT(*) AS cnt FROM comment_reactions WHERE comment_id = ? GROUP BY reaction_type');
$countStmt->execute([$commentId]);
$counts = ['shard' => 0, 'ward' => 0, 'ember' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $counts[$row['reaction_type']] = (int)$row['cnt'];
}

pw_json(['ok' => true, 'reactions' => $counts, 'myReaction' => $myReaction]);
