<?php
/**
 * Feeds the Home dashboard's "Content Drafts" card -- a glance at books
 * still in progress (writing_stage < 15 / Published), complementing Quick
 * Actions ("start something new") with "finish something in progress".
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('dashboards.view_home');

$db = pw_db();

$countStmt = $db->query('SELECT COUNT(*) AS c FROM books WHERE writing_stage < 15');
$count = (int)$countStmt->fetch()['c'];

$stmt = $db->query(
    'SELECT book_number, title, writing_stage FROM books
     WHERE writing_stage < 15
     ORDER BY writing_stage DESC, book_number ASC
     LIMIT 3'
);
$drafts = array_map(function ($r) {
    return [
        'book_number' => (int)$r['book_number'],
        'title' => $r['title'],
        'writing_stage' => (int)$r['writing_stage'],
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'draft_count' => $count, 'drafts' => $drafts]);
