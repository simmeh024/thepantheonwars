<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_admin();

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing book id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT book_number, title FROM books WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Book not found.', 404);
}

$stmt = $db->prepare('DELETE FROM books WHERE id = ?');
$stmt->execute([$id]);

pw_log_admin_activity(
    'book_deleted',
    'Deleted Book ' . $existing['book_number'] . ': "' . $existing['title'] . '".',
    $adminUser
);

pw_json(['ok' => true]);
