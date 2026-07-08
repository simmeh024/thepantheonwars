<?php
/**
 * Admin listing for Book Control (Lore Management > Book Control). Small,
 * fixed-size dataset (14 books today, maybe a few more later) so this is a
 * flat unpaginated list -- same pattern as dispatch-translations, not the
 * paginated members/topic-reports lists.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_admin();
$db = pw_db();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($q) > 200) {
    $q = substr($q, 0, 200);
}

$where = '';
$params = [];
if ($q !== '') {
    $where = 'WHERE title LIKE :q';
    $params[':q'] = '%' . $q . '%';
}

$stmt = $db->prepare(
    "SELECT id, book_number, saga_phase, writing_stage, title, status_label, meta_text,
            cover_image_url, preview_enabled, buy_kobo_url, buy_amazon_url, buy_apple_url, buy_bn_url
     FROM books
     $where
     ORDER BY book_number ASC"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    $hasBuyLink = !empty($r['buy_kobo_url']) || !empty($r['buy_amazon_url'])
        || !empty($r['buy_apple_url']) || !empty($r['buy_bn_url']);
    return [
        'id' => (int)$r['id'],
        'book_number' => (int)$r['book_number'],
        'saga_phase' => (int)$r['saga_phase'],
        'writing_stage' => (int)$r['writing_stage'],
        'title' => $r['title'],
        'status_label' => $r['status_label'],
        'meta_text' => $r['meta_text'],
        'cover_image_url' => $r['cover_image_url'],
        'preview_enabled' => (bool)$r['preview_enabled'],
        'has_buy_link' => $hasBuyLink,
    ];
}, $rows);

pw_json(['ok' => true, 'entries' => $out, 'total' => count($out)]);
