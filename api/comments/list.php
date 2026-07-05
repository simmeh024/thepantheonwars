<?php
require_once __DIR__ . '/../helpers.php';

// Rendering note: bodies/display names below are RAW text, not HTML-escaped.
// The front-end must render them with textContent (never innerHTML) to stay XSS-safe.

$board = isset($_GET['board']) ? trim($_GET['board']) : 'community';
if (!preg_match('/^[a-z0-9\-]{1,50}$/', $board)) {
    $board = 'community';
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT c.id, c.parent_id, c.depth, c.body, c.created_at, c.user_id, c.is_pinned, c.pinned_at,
            u.username, u.display_name, u.overlord_affinity, u.role
     FROM comments c
     JOIN users u ON u.id = c.user_id
     WHERE c.board = ? AND c.is_deleted = 0
     ORDER BY c.is_pinned DESC, c.created_at ASC
     LIMIT 500'
);
$stmt->execute([$board]);
$rows = $stmt->fetchAll();

// Total (all-board) post counts per author, so the forum card can show
// "N posts" next to the profile info without an extra round trip per row.
$postCounts = [];
$commentIds = [];
if ($rows) {
    $userIds = array_values(array_unique(array_map(function ($r) { return (int)$r['user_id']; }, $rows)));
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $countStmt = $db->prepare(
        "SELECT user_id, COUNT(*) AS cnt FROM comments WHERE is_deleted = 0 AND user_id IN ($placeholders) GROUP BY user_id"
    );
    $countStmt->execute($userIds);
    foreach ($countStmt->fetchAll() as $row) {
        $postCounts[(int)$row['user_id']] = (int)$row['cnt'];
    }
    $commentIds = array_map(function ($r) { return (int)$r['id']; }, $rows);
}

// Reaction counts per comment + the current viewer's own reaction, if any.
$reactionCounts = [];
$myReactions = [];
if ($commentIds) {
    $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
    $rStmt = $db->prepare(
        "SELECT comment_id, reaction_type, COUNT(*) AS cnt FROM comment_reactions WHERE comment_id IN ($placeholders) GROUP BY comment_id, reaction_type"
    );
    $rStmt->execute($commentIds);
    foreach ($rStmt->fetchAll() as $row) {
        $cid = (int)$row['comment_id'];
        if (!isset($reactionCounts[$cid])) {
            $reactionCounts[$cid] = ['shard' => 0, 'ward' => 0, 'ember' => 0];
        }
        $reactionCounts[$cid][$row['reaction_type']] = (int)$row['cnt'];
    }
}

$currentUser = pw_current_user();
$currentId = $currentUser ? (int)$currentUser['id'] : null;
$isAdmin = $currentUser ? in_array($currentUser['role'], ['admin', 'moderator'], true) : false;

if ($currentId !== null && $commentIds) {
    $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
    $myStmt = $db->prepare(
        "SELECT comment_id, reaction_type FROM comment_reactions WHERE user_id = ? AND comment_id IN ($placeholders)"
    );
    $myStmt->execute(array_merge([$currentId], $commentIds));
    foreach ($myStmt->fetchAll() as $row) {
        $myReactions[(int)$row['comment_id']] = $row['reaction_type'];
    }
}

$out = array_map(function ($r) use ($currentId, $isAdmin, $postCounts, $reactionCounts, $myReactions) {
    $userId = (int)$r['user_id'];
    $id = (int)$r['id'];
    return [
        'id' => $id,
        'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
        'depth' => (int)$r['depth'],
        'body' => $r['body'],
        'created_at' => $r['created_at'],
        'user_id' => $userId,
        'username' => $r['username'],
        'display_name' => $r['display_name'],
        'overlord_affinity' => $r['overlord_affinity'],
        'role' => $r['role'],
        'post_count' => isset($postCounts[$userId]) ? $postCounts[$userId] : 0,
        'canDelete' => $isAdmin || ($currentId !== null && $currentId === $userId),
        'canModerate' => $isAdmin,
        'isPinned' => (bool)$r['is_pinned'],
        'reactions' => isset($reactionCounts[$id]) ? $reactionCounts[$id] : ['shard' => 0, 'ward' => 0, 'ember' => 0],
        'myReaction' => isset($myReactions[$id]) ? $myReactions[$id] : null,
    ];
}, $rows);

pw_json(['ok' => true, 'comments' => $out]);
