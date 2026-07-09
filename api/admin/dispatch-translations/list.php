<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('dispatch_translations.view');

$db = pw_db();

$filter = isset($_GET['filter']) ? trim($_GET['filter']) : 'all';
if (!in_array($filter, ['all', 'translated', 'untranslated'], true)) {
    $filter = 'all';
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($q) > 200) {
    $q = substr($q, 0, 200);
}

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(d.subject LIKE :q1 OR d.sha LIKE :q2)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}
if ($filter === 'translated') {
    $where[] = 'dt.id IS NOT NULL';
} elseif ($filter === 'untranslated') {
    $where[] = 'dt.id IS NULL';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare(
    'SELECT d.id, d.sha, d.subject, d.body, d.tag, d.committed_at,
            dt.translation, dt.updated_at AS translation_updated_at
     FROM dispatch_entries d
     LEFT JOIN dispatch_translations dt ON dt.dispatch_id = d.id
     ' . $whereSql . '
     ORDER BY d.committed_at DESC, d.id DESC'
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
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
        'committed_at' => $r['committed_at'],
        'translation' => $r['translation'],
        'has_translation' => $r['translation'] !== null,
        'translation_updated_at' => $r['translation_updated_at'],
    ];
}, $rows);

pw_json(['ok' => true, 'entries' => $out]);
