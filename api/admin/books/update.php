<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/books-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('books.edit');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing book id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT * FROM books WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Book not found.', 404);
}

$data = pw_validate_book_input($input);

$dupStmt = $db->prepare('SELECT id FROM books WHERE book_number = ? AND id != ?');
$dupStmt->execute([$data['book_number'], $id]);
if ($dupStmt->fetch()) {
    pw_error('Another book already uses that book number.', 409);
}

$stmt = $db->prepare(
    'UPDATE books SET
        book_number = ?, saga_phase = ?, writing_stage = ?, title = ?, status_label = ?,
        meta_text = ?, description = ?, cover_image_url = ?, character_image_url = ?,
        character_alt = ?, preview_enabled = ?, preview_eyebrow = ?, preview_lede = ?,
        preview_hero_image_url = ?, preview_body = ?, preview_quote = ?, preview_quote_cite = ?,
        buy_kobo_url = ?, buy_amazon_url = ?, buy_apple_url = ?, buy_bn_url = ?
     WHERE id = ?'
);
$stmt->execute([
    $data['book_number'], $data['saga_phase'], $data['writing_stage'], $data['title'],
    $data['status_label'], $data['meta_text'], $data['description'],
    $data['cover_image_url'], $data['character_image_url'], $data['character_alt'],
    $data['preview_enabled'], $data['preview_eyebrow'], $data['preview_lede'],
    $data['preview_hero_image_url'], $data['preview_body'],
    $data['preview_quote'], $data['preview_quote_cite'],
    $data['buy_kobo_url'], $data['buy_amazon_url'], $data['buy_apple_url'], $data['buy_bn_url'],
    $id,
]);

$changes = [];
if ((int)$existing['writing_stage'] !== $data['writing_stage']) {
    $changes[] = 'writing stage ' . $existing['writing_stage'] . ' -> ' . $data['writing_stage'];
}
if ((bool)$existing['preview_enabled'] !== (bool)$data['preview_enabled']) {
    $changes[] = 'preview ' . ($data['preview_enabled'] ? 'enabled' : 'disabled');
}
if ($existing['title'] !== $data['title']) {
    $changes[] = 'title updated';
}
$summary = $changes ? (' (' . implode(', ', $changes) . ')') : '';

pw_log_admin_activity(
    'book_updated',
    'Updated Book ' . $data['book_number'] . ': "' . $data['title'] . '"' . $summary . '.',
    $adminUser
);

pw_json(['ok' => true]);
