<?php
require_once __DIR__ . '/../helpers.php';

// Public read (no login required) -- this is the profile members.js links
// to from the nav dropdown, the member list, and forum post author names.
// Deliberately excludes anything private: email, password stuff, "My Posts"
// edit/delete affordances, and the Re-Sync-affinity control all stay on the
// gated profile.html. This only ever returns what a stranger could already
// see elsewhere on the site (member list row + public post history).

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    pw_error('Missing member id.');
}

$db = pw_db();

$stmt = $db->prepare(
    'SELECT id, display_name, role, overlord_affinity, created_at, last_login_at, last_active_at,
       (last_active_at IS NOT NULL AND last_active_at >= (NOW() - INTERVAL 5 MINUTE)) AS is_online
     FROM users WHERE id = ?'
);
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    pw_error('That member no longer exists.', 404);
}

$countStmt = $db->prepare(
    'SELECT
       (SELECT COUNT(*) FROM comments WHERE user_id = ? AND is_deleted = 0) +
       (SELECT COUNT(*) FROM topics WHERE user_id = ? AND is_deleted = 0) AS cnt'
);
$countStmt->execute([$id, $id]);
$postCount = (int)$countStmt->fetch()['cnt'];

// Recent activity feed: topics and comments merged, newest first. Kept small
// (10) since this is a public "recent posts" list, not the full paginated
// history the owner sees on their own profile.
$stmt = $db->prepare(
    "(SELECT 'topic' AS kind, id, board, title AS heading, body, created_at
        FROM topics WHERE user_id = ? AND is_deleted = 0)
     UNION ALL
     (SELECT 'comment' AS kind, id, board, NULL AS heading, body, created_at
        FROM comments WHERE user_id = ? AND is_deleted = 0)
     ORDER BY created_at DESC
     LIMIT 10"
);
$stmt->execute([$id, $id]);
$recentPosts = $stmt->fetchAll();

pw_json([
    'ok' => true,
    'member' => [
        'id' => (int)$user['id'],
        'display_name' => $user['display_name'],
        'role' => $user['role'],
        'overlord_affinity' => $user['overlord_affinity'],
        'created_at' => $user['created_at'],
        'last_login_at' => $user['last_login_at'],
        'last_active_at' => $user['last_active_at'],
        'is_online' => (bool)$user['is_online'],
        'post_count' => $postCount,
    ],
    'recentPosts' => array_map(function ($r) {
        return [
            'kind' => $r['kind'],
            'id' => (int)$r['id'],
            'board' => $r['board'],
            'heading' => $r['heading'],
            'body' => $r['body'],
            'created_at' => $r['created_at'],
        ];
    }, $recentPosts),
]);
