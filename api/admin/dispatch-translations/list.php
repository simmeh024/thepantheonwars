<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../dispatch-translation-drafts.php';

pw_require_permission('dispatch_translations.view');

$db = pw_db();

$filter = isset($_GET['filter']) ? trim($_GET['filter']) : 'all';
if (!in_array($filter, ['all', 'translated', 'draft', 'untranslated'], true)) {
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
} elseif ($filter === 'draft') {
    $where[] = 'dt.id IS NULL AND dtd.id IS NOT NULL';
} elseif ($filter === 'untranslated') {
    $where[] = 'dt.id IS NULL AND dtd.id IS NULL';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare(
    'SELECT d.id, d.sha, d.subject, d.body, d.tag, d.committed_at,
            dt.translation, dt.updated_at AS translation_updated_at,
            dtd.draft AS generated_draft, dtd.updated_at AS draft_updated_at
     FROM dispatch_entries d
     LEFT JOIN dispatch_translations dt ON dt.dispatch_id = d.id
     LEFT JOIN dispatch_translation_drafts dtd ON dtd.dispatch_id = d.id
     ' . $whereSql . '
     ORDER BY d.committed_at DESC, d.id DESC'
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    $draftMetadata = pw_dispatch_end_user_draft($r['subject'], (string)$r['body'], $r['tag']);
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
        'generated_draft' => $r['generated_draft'],
        'has_draft' => $r['generated_draft'] !== null,
        'draft_updated_at' => $r['draft_updated_at'],
        'confidence' => $draftMetadata['confidence'],
    ];
}, $rows);

pw_json(['ok' => true, 'entries' => $out]);
