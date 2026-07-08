<?php
/** Full detail for a single book, used to populate the Book Control edit modal. */
require_once __DIR__ . '/../../helpers.php';

pw_require_admin();
$db = pw_db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    pw_error('Missing book id.');
}

$stmt = $db->prepare('SELECT * FROM books WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    pw_error('Book not found.', 404);
}

$row['id'] = (int)$row['id'];
$row['book_number'] = (int)$row['book_number'];
$row['saga_phase'] = (int)$row['saga_phase'];
$row['writing_stage'] = (int)$row['writing_stage'];
$row['preview_enabled'] = (bool)$row['preview_enabled'];

pw_json(['ok' => true, 'book' => $row]);
