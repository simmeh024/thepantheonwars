<?php
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/layers-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('worlds.edit');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing layer id.');
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT wl.id, wl.world_id, w.name AS world_name FROM world_layers wl
     JOIN worlds w ON w.id = wl.world_id WHERE wl.id = ?'
);
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Layer not found.', 404);
}

$data = pw_validate_layer_input($input);
$sublocations = pw_parse_sublocations_textarea(isset($input['sublocations_text']) ? $input['sublocations_text'] : '');
$quoteVariants = pw_validate_layer_quote_variants($input);

$db->beginTransaction();

$stmt = $db->prepare(
    'UPDATE world_layers SET name = ?, theme_tags = ?, tagline = ?, description = ?, quote_text = ?, quote_cite = ?, tint_key = ?
     WHERE id = ?'
);
$stmt->execute([
    $data['name'], $data['theme_tags'], $data['tagline'], $data['description'],
    $data['quote_text'], $data['quote_cite'], $data['tint_key'], $id,
]);

// Sublocations are replaced wholesale on every save -- simplest correct
// approach for a handful of rows, same pattern Forum Control uses for a
// board's role restrictions.
$db->prepare('DELETE FROM world_layer_sublocations WHERE layer_id = ?')->execute([$id]);
$subStmt = $db->prepare('INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (?, ?, ?)');
foreach ($sublocations as $i => $label) {
    $subStmt->execute([$id, $i + 1, $label]);
}

// Replaced wholesale on every save, same as the sublocations above.
pw_save_layer_quote_variants($db, $id, $quoteVariants);

$db->commit();

pw_log_admin_activity(
    'world_layer_updated',
    'Updated layer "' . $data['name'] . '" in world "' . $existing['world_name'] . '".',
    $adminUser
);

pw_json(['ok' => true]);
