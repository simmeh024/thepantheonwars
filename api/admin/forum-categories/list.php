<?php
/**
 * Admin listing for Forum Control's "Board Categories" list. Small,
 * fixed-size dataset -- same flat unpaginated pattern as the board list
 * below it. Each row includes its live board count (for the delete-guard).
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('forum_boards.view');
$db = pw_db();

$categories = $db->query(
    'SELECT fc.*, COUNT(fb.id) AS board_count
     FROM forum_categories fc
     LEFT JOIN forum_boards fb ON fb.category_id = fc.id
     GROUP BY fc.id
     ORDER BY fc.sort_order'
)->fetchAll();

$out = array_map(function ($c) {
    return [
        'id' => (int)$c['id'],
        'name' => $c['name'],
        'sort_order' => (int)$c['sort_order'],
        'board_count' => (int)$c['board_count'],
    ];
}, $categories);

pw_json(['ok' => true, 'entries' => $out, 'total' => count($out)]);
