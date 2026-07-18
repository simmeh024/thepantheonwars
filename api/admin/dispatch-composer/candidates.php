<?php
/**
 * Approved-dispatch reference list for the Composer's left panel. "Approved"
 * means it has a row in dispatch_translations -- the same definition
 * Translation Review uses for a dispatch being translated/live.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../dispatch-translation-drafts.php';

pw_require_permission('dispatch_composer.view');
$db = pw_db();

$composerPostId = isset($_GET['composer_post_id']) ? (int)$_GET['composer_post_id'] : 0;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($q) > 200) {
    $q = mb_substr($q, 0, 200);
}
$tag = isset($_GET['tag']) ? trim($_GET['tag']) : '';
if (!in_array($tag, ['feature', 'fix', 'update'], true)) {
    $tag = '';
}
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$where = ['dt.id IS NOT NULL'];
$params = [];
if ($q !== '') {
    $where[] = '(d.subject LIKE ? OR dt.translation LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($tag !== '') {
    $where[] = 'd.tag = ?';
    $params[] = $tag;
}
if ($dateFrom !== '' && preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateFrom)) {
    $where[] = 'd.committed_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateTo)) {
    $where[] = 'd.committed_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT d.id, d.sha, d.subject, d.body, d.tag, d.committed_at, d.url,
            dt.translation
     FROM dispatch_entries d
     JOIN dispatch_translations dt ON dt.dispatch_id = d.id
     $whereSql
     ORDER BY d.committed_at DESC, d.id DESC
     LIMIT 200"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$attachedIds = [];
if ($composerPostId > 0) {
    $attachedStmt = $db->prepare('SELECT dispatch_id FROM dispatch_composer_items WHERE composer_post_id = ?');
    $attachedStmt->execute([$composerPostId]);
    $attachedIds = array_flip(array_map('intval', array_column($attachedStmt->fetchAll(), 'dispatch_id')));
}

$usedElsewhere = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $usedStmt = $db->prepare(
        "SELECT ci.dispatch_id, cp.id AS composer_post_id, cp.title
         FROM dispatch_composer_items ci
         JOIN dispatch_composer_posts cp ON cp.id = ci.composer_post_id
         WHERE cp.status = 'published' AND ci.dispatch_id IN ($placeholders)"
    );
    $usedStmt->execute($ids);
    foreach ($usedStmt->fetchAll() as $r) {
        $usedElsewhere[(int)$r['dispatch_id']][] = ['composer_post_id' => (int)$r['composer_post_id'], 'title' => $r['title']];
    }
}

$out = array_map(function ($r) use ($attachedIds, $usedElsewhere) {
    $id = (int)$r['id'];
    $draftMetadata = pw_dispatch_end_user_draft($r['subject'], (string)$r['body'], $r['tag'], []);
    return [
        'id' => $id,
        'sha' => $r['sha'],
        'short_sha' => substr($r['sha'], 0, 7),
        'subject' => $r['subject'],
        'tag' => $r['tag'],
        'committed_at' => $r['committed_at'],
        'url' => $r['url'],
        'translation' => $r['translation'],
        'confidence' => $draftMetadata['confidence'],
        'attached' => isset($attachedIds[$id]),
        'published_elsewhere' => isset($usedElsewhere[$id]) ? $usedElsewhere[$id] : [],
    ];
}, $rows);

pw_json(['ok' => true, 'dispatches' => $out]);
