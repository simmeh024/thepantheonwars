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

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($q) > 200) {
    $q = substr($q, 0, 200);
}

$validTags = ['feature', 'improvement', 'fix', 'performance', 'ui_ux', 'lore', 'infrastructure', 'refactor', 'experimental'];
$tags = [];
if (isset($_GET['tags']) && trim($_GET['tags']) !== '') {
    $requested = array_map('trim', explode(',', $_GET['tags']));
    $tags = array_values(array_intersect($validTags, $requested));
}

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(d.subject LIKE :q OR d.body LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($tags) {
    $tagPlaceholders = [];
    foreach ($tags as $i => $t) {
        $key = ':tag' . $i;
        $tagPlaceholders[] = $key;
        $params[$key] = $t;
    }
    $where[] = 'd.tag IN (' . implode(',', $tagPlaceholders) . ')';
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
    'SELECT d.id, d.sha, d.subject, d.body, d.tag, d.author, d.committed_at, d.url,
            COALESCE(rc.like_count, 0) AS like_count,
            COALESCE(rc.dislike_count, 0) AS dislike_count
     FROM dispatch_entries d
     LEFT JOIN (
       SELECT dispatch_id,
              SUM(reaction_type = \'like\') AS like_count,
              SUM(reaction_type = \'dislike\') AS dislike_count
       FROM dispatch_reactions
       GROUP BY dispatch_id
     ) rc ON rc.dispatch_id = d.id
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

$myReactions = [];
$currentUser = pw_current_user();
if ($currentUser && $rows) {
    $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $params[] = $currentUser['id'];
    $rstmt = $db->prepare("SELECT dispatch_id, reaction_type FROM dispatch_reactions WHERE dispatch_id IN ($placeholders) AND user_id = ?");
    $rstmt->execute($params);
    foreach ($rstmt->fetchAll() as $r) {
        $myReactions[(int)$r['dispatch_id']] = $r['reaction_type'];
    }
}

$out = array_map(function ($r) use ($myReactions) {
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
        'like_count' => (int)$r['like_count'],
        'dislike_count' => (int)$r['dislike_count'],
        'my_reaction' => isset($myReactions[(int)$r['id']]) ? $myReactions[(int)$r['id']] : null,
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
