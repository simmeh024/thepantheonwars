<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/worlds-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('worlds.edit');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing world id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT * FROM worlds WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('World not found.', 404);
}

// Slug is immutable once created (worlds.js and any bookmarked #anchor links
// key off it) -- accepted from the client only to detect an attempted
// change, then silently ignored rather than trusted, same as Forum Control's
// board slug.
$data = pw_validate_world_input($input);

$hasAccent = pw_worlds_has_accent_column();
$stmt = $db->prepare(
    'UPDATE worlds SET
        name = ?, tagline = ?, card_blurb = ?, thumb_image_url = ?, portrait_image_url = ?,
        status = ?, lore_status_label = ?,
        intro_paragraph_1 = ?, intro_paragraph_2 = ?, layout_orientation = ?,
        altitude_top_label = ?, altitude_bottom_label = ?,
        map_thumb_image_url = ?, map_full_image_url = ?, map_caption = ?'
    . ($hasAccent ? ', accent_rgb = ?' : '') . '
     WHERE id = ?'
);
$params = [
    $data['name'], $data['tagline'], $data['card_blurb'],
    $data['thumb_image_url'], $data['portrait_image_url'],
    $data['status'], $data['lore_status_label'],
    $data['intro_paragraph_1'], $data['intro_paragraph_2'], $data['layout_orientation'],
    $data['altitude_top_label'], $data['altitude_bottom_label'],
    $data['map_thumb_image_url'], $data['map_full_image_url'], $data['map_caption'],
];
if ($hasAccent) {
    $params[] = $data['accent_rgb'];
}
$params[] = $id;
$stmt->execute($params);

$changes = [];
if ($existing['status'] !== $data['status']) {
    $changes[] = 'status ' . $existing['status'] . ' -> ' . $data['status'];
}
if ($existing['name'] !== $data['name']) {
    $changes[] = 'name updated';
}
$summary = $changes ? (' (' . implode(', ', $changes) . ')') : '';

pw_log_admin_activity('world_updated', 'Updated world "' . $data['name'] . '"' . $summary . '.', $adminUser);

// Broadcast a "new world to explore" notification only on the transition
// into available -- never on every save of an already-available world
// (e.g. a typo fix to its description shouldn't re-notify everyone).
if ($data['status'] === 'available' && $existing['status'] !== 'available') {
    pw_notify_world_available($id);
}

pw_json(['ok' => true]);
