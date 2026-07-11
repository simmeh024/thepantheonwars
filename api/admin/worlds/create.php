<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/worlds-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('worlds.edit');

$input = pw_input();
pw_require_csrf($input);

$slug = pw_validate_world_slug($input);
$data = pw_validate_world_input($input);

$db = pw_db();

$dupStmt = $db->prepare('SELECT id FROM worlds WHERE slug = ?');
$dupStmt->execute([$slug]);
if ($dupStmt->fetch()) {
    pw_error('A world with that slug already exists.', 409);
}

$maxSort = $db->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM worlds')->fetch();
$sortOrder = (int)$maxSort['m'] + 1;

$stmt = $db->prepare(
    'INSERT INTO worlds (
        slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url,
        overlord_name, overlord_title, overlord_page_slug, status, lore_status_label,
        intro_paragraph_1, intro_paragraph_2, layout_orientation,
        altitude_top_label, altitude_bottom_label,
        map_thumb_image_url, map_full_image_url, map_caption, sort_order
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $slug, $data['name'], $data['tagline'], $data['card_blurb'],
    $data['thumb_image_url'], $data['portrait_image_url'],
    $data['overlord_name'], $data['overlord_title'], $data['overlord_page_slug'],
    $data['status'], $data['lore_status_label'],
    $data['intro_paragraph_1'], $data['intro_paragraph_2'], $data['layout_orientation'],
    $data['altitude_top_label'], $data['altitude_bottom_label'],
    $data['map_thumb_image_url'], $data['map_full_image_url'], $data['map_caption'],
    $sortOrder,
]);
$worldId = (int)$db->lastInsertId();

pw_log_admin_activity('world_created', 'Added world "' . $data['name'] . '".', $adminUser);

pw_json(['ok' => true, 'id' => $worldId]);
