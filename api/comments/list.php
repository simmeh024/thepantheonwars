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
    'SELECT c.id, c.parent_id, c.body, c.created_at, c.user_id, u.username, u.display_name, u.overlord_affinity
     FROM comments c
     JOIN users u ON u.id = c.user_id
     WHERE c.board = ? AND c.is_deleted = 0
     ORDER BY c.created_at ASC
     LIMIT 500'
);
$stmt->execute([$board]);
$rows = $stmt->fetchAll();

$currentUser = pw_current_user();
$currentId = $currentUser ? (int)$currentUser['id'] : null;
$isAdmin = $currentUser ? (bool)$currentUser['is_admin'] : false;

$out = array_map(function ($r) use ($currentId, $isAdmin) {
    return [
        'id' => (int)$r['id'],
        'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
        'body' => $r['body'],
        'created_at' => $r['created_at'],
        'username' => $r['username'],
        'display_name' => $r['display_name'],
        'overlord_affinity' => $r['overlord_affinity'],
        'canDelete' => $isAdmin || ($currentId !== null && $currentId === (int)$r['user_id']),
    ];
}, $rows);

pw_json(['ok' => true, 'comments' => $out]);
