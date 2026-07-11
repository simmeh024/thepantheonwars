<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/overlords-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('overlords.edit');

$input = pw_input();
pw_require_csrf($input);

$slug = pw_validate_overlord_slug($input);
$data = pw_validate_overlord_input($input);

$db = pw_db();

$dupStmt = $db->prepare('SELECT id FROM overlords WHERE slug = ?');
$dupStmt->execute([$slug]);
if ($dupStmt->fetch()) {
    pw_error('An overlord with that slug already exists.', 409);
}

$maxSort = $db->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM overlords')->fetch();
$sortOrder = (int)$maxSort['m'] + 1;

$stmt = $db->prepare(
    'INSERT INTO overlords (
        slug, name, epithet, world_id, pronoun_possessive, status,
        portrait_image_url, card_teaser, bio_paragraph_1, bio_paragraph_2, bio_paragraph_3,
        quote_text, quote_cite, accent_color, accent_glow, meta_title, meta_description, sort_order
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $slug, $data['name'], $data['epithet'], $data['world_id'], $data['pronoun_possessive'], $data['status'],
    $data['portrait_image_url'], $data['card_teaser'], $data['bio_paragraph_1'], $data['bio_paragraph_2'], $data['bio_paragraph_3'],
    $data['quote_text'], $data['quote_cite'], $data['accent_color'], $data['accent_glow'],
    $data['meta_title'], $data['meta_description'], $sortOrder,
]);
$overlordId = (int)$db->lastInsertId();

if ($data['world_id']) {
    $db->prepare('UPDATE worlds SET overlord_id = ? WHERE id = ?')->execute([$overlordId, $data['world_id']]);
}

pw_log_admin_activity('overlord_created', 'Added overlord "' . $data['name'] . '".', $adminUser);

pw_json(['ok' => true, 'id' => $overlordId]);
