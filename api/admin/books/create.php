<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/books-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('books.edit');

$input = pw_input();
pw_require_csrf($input);

$data = pw_validate_book_input($input);

$db = pw_db();

$dupStmt = $db->prepare('SELECT id FROM books WHERE book_number = ?');
$dupStmt->execute([$data['book_number']]);
if ($dupStmt->fetch()) {
    pw_error('A book with that book number already exists.', 409);
}

$maxSort = $db->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM books')->fetch();
$sortOrder = (int)$maxSort['m'] + 1;

$stmt = $db->prepare(
    'INSERT INTO books (
        book_number, saga_phase, writing_stage, title, status_label, meta_text, description,
        cover_image_url, character_image_url, character_alt,
        preview_enabled, preview_eyebrow, preview_lede, preview_hero_image_url, preview_body,
        preview_quote, preview_quote_cite,
        buy_kobo_url, buy_amazon_url, buy_apple_url, buy_bn_url, sort_order
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $data['book_number'], $data['saga_phase'], $data['writing_stage'], $data['title'],
    $data['status_label'], $data['meta_text'], $data['description'],
    $data['cover_image_url'], $data['character_image_url'], $data['character_alt'],
    $data['preview_enabled'], $data['preview_eyebrow'], $data['preview_lede'],
    $data['preview_hero_image_url'], $data['preview_body'],
    $data['preview_quote'], $data['preview_quote_cite'],
    $data['buy_kobo_url'], $data['buy_amazon_url'], $data['buy_apple_url'], $data['buy_bn_url'],
    $sortOrder,
]);
$bookId = (int)$db->lastInsertId();

pw_log_admin_activity(
    'book_created',
    'Added Book ' . $data['book_number'] . ': "' . $data['title'] . '".',
    $adminUser
);

pw_json(['ok' => true, 'id' => $bookId]);
