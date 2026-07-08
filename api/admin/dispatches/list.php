<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../dispatch-helpers.php';

pw_require_admin();

$db = pw_db();

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if ($perPage <= 0) {
    $perPage = 25;
}
if ($perPage > 200) {
    $perPage = 200;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($q) > 200) {
    $q = substr($q, 0, 200);
}

$validTags = pw_dispatch_valid_tags();
$tag = isset($_GET['tag']) ? trim($_GET['tag']) : '';
if (!in_array($tag, $validTags, true)) {
    $tag = '';
}

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(d.subject LIKE :q1 OR d.sha LIKE :q2)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}
if ($tag !== '') {
    $where[] = 'd.tag = :tag';
    $params[':tag'] = $tag;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $db->prepare('SELECT COUNT(*) AS c FROM dispatch_entries d ' . $whereSql);
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$total = (int)$countStmt->fetch()['c'];
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    'SELECT d.id, d.sha, d.subject, d.tag, d.author, d.committed_at,
            (dt.id IS NOT NULL) AS has_translation
     FROM dispatch_entries d
     LEFT JOIN dispatch_translations dt ON dt.dispatch_id = d.id
     ' . $whereSql . '
     ORDER BY d.committed_at DESC, d.id DESC
     LIMIT :limit OFFSET :offset'
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'sha' => $r['sha'],
        'short_sha' => substr($r['sha'], 0, 7),
        'subject' => $r['subject'],
        'tag' => $r['tag'],
        'author' => $r['author'],
        'committed_at' => $r['committed_at'],
        'has_translation' => (bool)$r['has_translation'],
    ];
}, $rows);

pw_json([
    'ok' => true,
    'entries' => $out,
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'total_pages' => $totalPages,
]);
