<?php
require_once __DIR__ . '/../helpers.php';

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if ($perPage <= 0) {
    $perPage = 10;
}
if ($perPage > 500) {
    $perPage = 500;
}

$db = pw_db();

$total = (int)$db->query('SELECT COUNT(*) AS c FROM dispatch_entries')->fetch()['c'];
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    'SELECT id, sha, subject, body, tag, author, committed_at, url
     FROM dispatch_entries
     ORDER BY committed_at DESC, id DESC
     LIMIT :limit OFFSET :offset'
);
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
        'body' => $r['body'],
        'tag' => $r['tag'],
        'author' => $r['author'],
        'committed_at' => $r['committed_at'],
        'url' => $r['url'],
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
