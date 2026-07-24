<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('dashboards.view_home');

$db = pw_db();

// Worlds and Overlords are static lore pages (worlds.html / overlords.html),
// not database-backed content, so these counts are maintained by hand and
// only need to change when a new world card is unlocked or a new Overlord
// profile page ships. Currently: 4 unlocked worlds (Neoh, High Hammer,
// Cerius, Asmecu) out of 13 total, and 6 revealed Overlords out of 12.
const PW_ACTIVE_WORLDS = 4;
const PW_ACTIVE_OVERLORDS = 6;

$totalMembers = (int)$db->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];

$totalForumPosts =
    (int)$db->query('SELECT COUNT(*) AS c FROM topics WHERE is_deleted = 0')->fetch()['c'] +
    (int)$db->query('SELECT COUNT(*) AS c FROM comments WHERE is_deleted = 0')->fetch()['c'];

$totalDispatches = (int)$db->query('SELECT COUNT(*) AS c FROM dispatch_entries')->fetch()['c'];

$totalNewsposts = (int)$db->query('SELECT COUNT(*) AS c FROM news_posts')->fetch()['c'];

$totalNewsComments = (int)$db->query('SELECT COUNT(*) AS c FROM news_comments')->fetch()['c'];

$todayDispatches = (int)$db->query(
    "SELECT COUNT(*) AS c FROM dispatch_entries WHERE DATE(committed_at) = CURDATE()"
)->fetch()['c'];

$categoryStmt = $db->query(
    "SELECT tag, COUNT(*) AS c FROM dispatch_entries
     WHERE DATE(committed_at) = CURDATE()
     GROUP BY tag ORDER BY c DESC, tag ASC LIMIT 1"
);
$categoryRow = $categoryStmt->fetch();
$mostActiveCategory = $categoryRow ? $categoryRow['tag'] : null;

pw_json([
    'ok' => true,
    'total_members' => $totalMembers,
    'total_forum_posts' => $totalForumPosts,
    'total_dispatches' => $totalDispatches,
    'total_newsposts' => $totalNewsposts,
    'total_news_comments' => $totalNewsComments,
    'active_worlds' => PW_ACTIVE_WORLDS,
    'active_overlords' => PW_ACTIVE_OVERLORDS,
    'today_dispatches' => $todayDispatches,
    'most_active_category' => $mostActiveCategory,
]);
