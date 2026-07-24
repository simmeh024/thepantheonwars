<?php
require_once __DIR__ . '/dialogue-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);

$adminUser = pw_require_permission('dialogues.edit');
$input = pw_input();
pw_require_csrf($input);
$overlordId = isset($input['overlord_id']) ? (int)$input['overlord_id'] : 0;
if ($overlordId <= 0) pw_error('Missing Overlord.');

$tree = pw_validate_dialogue_tree($input['tree'] ?? null);
$encoded = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encoded === false || strlen($encoded) > 60000) pw_error('This dialogue tree is too large to save.');
$enabled = !empty($input['is_enabled']) ? 1 : 0;

$db = pw_db();
if (!pw_dialogue_trees_ready($db)) {
    pw_error('Dialogue Tree Control needs its database migration before it can be used.', 503);
}

try {
    $overlordStmt = $db->prepare('SELECT name FROM overlords WHERE id = ?');
    $overlordStmt->execute([$overlordId]);
    $overlord = $overlordStmt->fetch();
    if (!$overlord) pw_error('Overlord not found.', 404);
    $stmt = $db->prepare(
        'INSERT INTO overlord_dialogue_trees (overlord_id, is_enabled, tree_json)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), tree_json = VALUES(tree_json)'
    );
    $stmt->execute([$overlordId, $enabled, $encoded]);
} catch (Throwable $e) {
    pw_error('Could not save this dialogue tree.', 500);
}

pw_log_admin_activity(
    'overlord_dialogue_tree_updated',
    'Updated custom dialogue tree for Overlord "' . $overlord['name'] . '"' . ($enabled ? '.' : ' (disabled).'),
    $adminUser
);

pw_json(['ok' => true]);
