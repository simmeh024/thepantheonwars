<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('dashboards.view');

$db = pw_db();

// "Active" means last_active_at was refreshed (via api/session-check.php,
// which pings on every active session) within the last 24 hours.
$activeStmt = $db->prepare(
    "SELECT role, COUNT(*) AS c
     FROM users
     WHERE last_active_at IS NOT NULL AND last_active_at >= (NOW() - INTERVAL 24 HOUR)
     GROUP BY role"
);
$activeStmt->execute();
$activeByRole = ['member' => 0, 'moderator' => 0, 'admin' => 0];
foreach ($activeStmt->fetchAll() as $row) {
    if (isset($activeByRole[$row['role']])) {
        $activeByRole[$row['role']] = (int)$row['c'];
    }
}

// Banned in the last 24h -- banned_at is refreshed whenever an account is
// (re-)banned, and cleared entirely on unban, so this only counts accounts
// that are currently banned AND were banned/re-banned within the window.
$bannedStmt = $db->query(
    "SELECT COUNT(*) AS c FROM users
     WHERE banned_at IS NOT NULL AND banned_at >= (NOW() - INTERVAL 24 HOUR)"
);
$banned24h = (int)$bannedStmt->fetch()['c'];

// New forum posts = new topics + new replies in the last 24h, excluding
// anything soft-deleted.
$postsStmt = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM topics WHERE is_deleted = 0 AND created_at >= (NOW() - INTERVAL 24 HOUR)) +
        (SELECT COUNT(*) FROM comments WHERE is_deleted = 0 AND created_at >= (NOW() - INTERVAL 24 HOUR))
        AS c"
);
$forumPosts24h = (int)$postsStmt->fetch()['c'];

pw_json([
    'ok' => true,
    'members_active_24h' => $activeByRole['member'],
    'banned_24h' => $banned24h,
    'admins_active_24h' => $activeByRole['admin'],
    'moderators_active_24h' => $activeByRole['moderator'],
    'forum_posts_24h' => $forumPosts24h,
]);
