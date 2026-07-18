<?php
/** Composer dashboard: draft/ready/published/archived articles with filters. */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('dispatch_composer.view');
$db = pw_db();

$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
if (!in_array($status, ['all', 'draft', 'ready', 'published', 'archived'], true)) {
    $status = 'all';
}
$creatorId = isset($_GET['creator_id']) ? (int)$_GET['creator_id'] : 0;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($q) > 200) {
    $q = mb_substr($q, 0, 200);
}

$where = [];
$params = [];
if ($status !== 'all') {
    $where[] = 'cp.status = ?';
    $params[] = $status;
}
if ($creatorId > 0) {
    $where[] = 'cp.created_by = ?';
    $params[] = $creatorId;
}
if ($q !== '') {
    $where[] = 'cp.title LIKE ?';
    $params[] = '%' . $q . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare(
    "SELECT cp.id, cp.title, cp.status, cp.updated_at, cp.published_at, cp.news_post_id,
            creator.username AS creator_username, updater.username AS updater_username,
            np.slug AS news_slug,
            (SELECT COUNT(*) FROM dispatch_composer_items ci WHERE ci.composer_post_id = cp.id) AS dispatch_count
     FROM dispatch_composer_posts cp
     LEFT JOIN users creator ON creator.id = cp.created_by
     LEFT JOIN users updater ON updater.id = cp.updated_by
     LEFT JOIN news_posts np ON np.id = cp.news_post_id
     $whereSql
     ORDER BY cp.updated_at DESC, cp.id DESC"
);
$stmt->execute($params);

$out = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'title' => $r['title'] !== '' ? $r['title'] : '(untitled draft)',
        'status' => $r['status'],
        'updated_at' => $r['updated_at'],
        'published_at' => $r['published_at'],
        'dispatch_count' => (int)$r['dispatch_count'],
        'creator_username' => $r['creator_username'],
        'updater_username' => $r['updater_username'],
        'news_post_id' => $r['news_post_id'] !== null ? (int)$r['news_post_id'] : null,
        'news_slug' => $r['news_slug'],
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'posts' => $out]);
