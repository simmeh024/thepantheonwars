<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/known-figures-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('known_figures.edit');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing known figure id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT * FROM known_figures WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Known figure not found.', 404);
}

// Slug is immutable once created -- same convention as Overlord/World/Forum
// Control's own slug fields.
$data = pw_validate_known_figure_input($input);

$stmt = $db->prepare(
    'UPDATE known_figures SET
        name = ?, eyebrow = ?, status_line = ?, portrait_image_url = ?,
        body_paragraph_1 = ?, body_paragraph_2 = ?, quote_text = ?, quote_cite = ?,
        accent_color = ?, motif = ?, signature_label = ?, is_published = ?
     WHERE id = ?'
);
$stmt->execute([
    $data['name'], $data['eyebrow'], $data['status_line'], $data['portrait_image_url'],
    $data['body_paragraph_1'], $data['body_paragraph_2'], $data['quote_text'], $data['quote_cite'],
    $data['accent_color'], $data['motif'], $data['signature_label'], $data['is_published'],
    $id,
]);

pw_log_admin_activity('known_figure_updated', 'Updated known figure "' . $data['name'] . '".', $adminUser);

pw_json(['ok' => true]);
