<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/known-figures-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('known_figures.edit');

$input = pw_input();
pw_require_csrf($input);

$slug = pw_validate_known_figure_slug($input);
$data = pw_validate_known_figure_input($input);

$db = pw_db();

$dupStmt = $db->prepare('SELECT id FROM known_figures WHERE slug = ?');
$dupStmt->execute([$slug]);
if ($dupStmt->fetch()) {
    pw_error('A known figure with that slug already exists.', 409);
}

$maxSort = $db->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM known_figures')->fetch();
$sortOrder = (int)$maxSort['m'] + 1;

$stmt = $db->prepare(
    'INSERT INTO known_figures (
        slug, name, eyebrow, status_line, portrait_image_url,
        body_paragraph_1, body_paragraph_2, quote_text, quote_cite,
        accent_color, motif, signature_label, is_published, sort_order
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $slug, $data['name'], $data['eyebrow'], $data['status_line'], $data['portrait_image_url'],
    $data['body_paragraph_1'], $data['body_paragraph_2'], $data['quote_text'], $data['quote_cite'],
    $data['accent_color'], $data['motif'], $data['signature_label'], $data['is_published'], $sortOrder,
]);
$figureId = (int)$db->lastInsertId();

pw_log_admin_activity('known_figure_created', 'Added known figure "' . $data['name'] . '".', $adminUser);

pw_json(['ok' => true, 'id' => $figureId]);
