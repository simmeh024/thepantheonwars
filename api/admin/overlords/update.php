<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/overlords-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('overlords.edit');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing overlord id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT * FROM overlords WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Overlord not found.', 404);
}

// Slug is immutable once created (overlord.html?slug= links and any
// bookmarked/redirected legacy URLs key off it) -- same convention as
// World/Forum Control's own slug fields.
$data = pw_validate_overlord_input($input);

$stmt = $db->prepare(
    'UPDATE overlords SET
        name = ?, epithet = ?, world_id = ?, pronoun_possessive = ?, status = ?,
        portrait_image_url = ?, card_teaser = ?, bio_paragraph_1 = ?, bio_paragraph_2 = ?, bio_paragraph_3 = ?,
        quote_text = ?, quote_cite = ?, decrees = ?, accent_color = ?, accent_glow = ?, meta_title = ?, meta_description = ?
     WHERE id = ?'
);
$stmt->execute([
    $data['name'], $data['epithet'], $data['world_id'], $data['pronoun_possessive'], $data['status'],
    $data['portrait_image_url'], $data['card_teaser'], $data['bio_paragraph_1'], $data['bio_paragraph_2'], $data['bio_paragraph_3'],
    $data['quote_text'], $data['quote_cite'], $data['decrees'], $data['accent_color'], $data['accent_glow'],
    $data['meta_title'], $data['meta_description'],
    $id,
]);

// Clear this overlord's link from whatever world previously pointed to it,
// then point the newly-assigned world (if any) at it -- keeps the pointer
// single-owner even when an overlord is reassigned to a different world.
$db->prepare('UPDATE worlds SET overlord_id = NULL WHERE overlord_id = ?')->execute([$id]);
if ($data['world_id']) {
    $db->prepare('UPDATE worlds SET overlord_id = ? WHERE id = ?')->execute([$id, $data['world_id']]);
}

$changes = [];
if ((int)$existing['world_id'] !== (int)$data['world_id']) {
    $changes[] = 'world assignment changed';
}
if ($existing['status'] !== $data['status']) {
    $changes[] = 'status ' . $existing['status'] . ' -> ' . $data['status'];
}
$summary = $changes ? (' (' . implode(', ', $changes) . ')') : '';

pw_log_admin_activity('overlord_updated', 'Updated overlord "' . $data['name'] . '"' . $summary . '.', $adminUser);

pw_json(['ok' => true]);
