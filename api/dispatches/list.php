<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../dispatch-helpers.php';
require_once __DIR__ . '/../dispatch-diff-context.php';

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if ($perPage <= 0) {
    $perPage = 10;
}
if ($perPage > 500) {
    $perPage = 500;
}

$db = pw_db();

// This table was introduced after dispatches were already live. Treat it as
// optional so a site awaiting the migration still serves its public log.
$hasDiffContext = false;
try {
    $db->query('SELECT 1 FROM dispatch_diff_context LIMIT 1');
    $hasDiffContext = true;
} catch (PDOException $e) {
    // The public list gracefully falls back to its normal chronological order.
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($q) > 200) {
    $q = substr($q, 0, 200);
}
$dispatchId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$validTags = ['feature', 'improvement', 'fix', 'performance', 'ui_ux', 'lore', 'infrastructure', 'refactor', 'experimental'];
$tags = [];
if (isset($_GET['tags']) && trim($_GET['tags']) !== '') {
    $requested = array_map('trim', explode(',', $_GET['tags']));
    $tags = array_values(array_intersect($validTags, $requested));
}

// A hidden dispatch is invisible to every end user, including through the
// ?dispatch=<id> deep link below -- hiding must not be defeatable by knowing
// the id.
$where = [];
$params = [];
if (pw_dispatch_has_visibility_column($db)) {
    $where[] = 'd.is_hidden = 0';
}
if ($q !== '') {
    $where[] = '(d.subject LIKE :q1 OR d.body LIKE :q2)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}
if ($dispatchId > 0) {
    $where[] = 'd.id = :dispatch_id';
    $params[':dispatch_id'] = $dispatchId;
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

$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';
$sortOrders = [
    'newest' => 'd.committed_at DESC, d.id DESC',
    'oldest' => 'd.committed_at ASC, d.id ASC',
    'popular' => 'COALESCE(rc.like_count, 0) DESC, d.committed_at DESC, d.id DESC',
    'discussed' => '(COALESCE(rc.like_count, 0) + COALESCE(rc.dislike_count, 0)) DESC, d.committed_at DESC, d.id DESC',
    'largest' => $hasDiffContext
        ? 'COALESCE(dctx.files_changed, 0) DESC, d.committed_at DESC, d.id DESC'
        : 'd.committed_at DESC, d.id DESC',
];
$orderBy = $sortOrders[$sort] ?? $sortOrders['newest'];

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
            COALESCE(rc.dislike_count, 0) AS dislike_count,
            dt.translation,
            (dt.id IS NOT NULL) AS has_translation
     FROM dispatch_entries d
     LEFT JOIN (
       SELECT dispatch_id,
              SUM(reaction_type = \'like\') AS like_count,
              SUM(reaction_type = \'dislike\') AS dislike_count
       FROM dispatch_reactions
       GROUP BY dispatch_id
     ) rc ON rc.dispatch_id = d.id
     LEFT JOIN dispatch_translations dt ON dt.dispatch_id = d.id
     ' . ($hasDiffContext ? 'LEFT JOIN dispatch_diff_context dctx ON dctx.dispatch_id = d.id' : '') . '
     ' . $whereSql . '
     ORDER BY ' . $orderBy . '
     LIMIT :limit OFFSET :offset'
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
$contexts = pw_get_dispatch_diff_contexts($db, array_column($rows, 'id'));

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

$out = array_map(function ($r) use ($myReactions, $contexts) {
    $context = $contexts[(int)$r['id']] ?? [];
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
        'translation' => $r['translation'],
        'has_translation' => (bool)$r['has_translation'],
        'files_changed' => isset($context['files_changed']) ? (int)$context['files_changed'] : null,
        'affected_areas' => array_values($context['areas'] ?? []),
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
