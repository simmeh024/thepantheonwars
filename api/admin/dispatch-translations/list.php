<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../dispatch-translation-drafts.php';

$currentUser = pw_require_permission('dispatch_translations.view');

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

$contexts = pw_get_dispatch_diff_contexts($db, array_column($rows, 'id'));
$recentTranslations = [];
try {
    $recentTranslations = array_column(
        $db->query('SELECT translation FROM dispatch_translations ORDER BY updated_at DESC, id DESC LIMIT 20')->fetchAll(),
        'translation'
    );
} catch (PDOException $e) {
    // Keep the translations list available if an older deployment is missing
    // a dependency needed only for repetition-aware confidence metadata.
}

$feedback = [];
try {
    $feedbackStmt = $db->prepare(
        "SELECT dispatch_id,
                SUM(rating = 'good') AS good,
                SUM(rating = 'bad') AS bad,
                MAX(CASE WHEN rated_by_user_id = ? THEN rating ELSE NULL END) AS my_rating
         FROM dispatch_translation_feedback
         GROUP BY dispatch_id"
    );
    $feedbackStmt->execute([(int)$currentUser['id']]);
    foreach ($feedbackStmt->fetchAll() as $row) {
        $feedback[(int)$row['dispatch_id']] = [
            'good' => (int)$row['good'],
            'bad' => (int)$row['bad'],
            'my_rating' => $row['my_rating'],
        ];
    }
} catch (PDOException $e) {
    // Keep the translations list available if the quality-feedback
    // migration has not been applied yet.
}

$out = array_map(function ($r) use ($contexts, $recentTranslations, $feedback) {
    $draftMetadata = pw_dispatch_end_user_draft($r['subject'], (string)$r['body'], $r['tag'], [
        'diff_context' => $contexts[(int)$r['id']] ?? [],
        'recent_translations' => $recentTranslations,
    ]);
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
        'feedback' => $feedback[(int)$r['id']] ?? ['good' => 0, 'bad' => 0, 'my_rating' => null],
    ];
}, $rows);

pw_json(['ok' => true, 'entries' => $out]);
