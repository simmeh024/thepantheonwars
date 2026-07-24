<?php
require_once __DIR__ . '/dialogue-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);

$adminUser = pw_require_permission('dialogues.edit');
$input = pw_input();
pw_require_csrf($input);
$overlordId = isset($input['overlord_id']) ? (int)$input['overlord_id'] : 0;
$versionId = isset($input['version_id']) ? (int)$input['version_id'] : 0;
if ($overlordId <= 0 || $versionId <= 0) pw_error('Choose a version to restore.');

$db = pw_db();
if (!pw_dialogue_tree_publish_ready($db) || !pw_dialogue_tree_versions_ready($db)) {
    pw_error('The Dialogue Editor Workflow migration still needs to be run.', 503);
}
try {
    $versionStmt = $db->prepare('SELECT tree_json, version_number FROM overlord_dialogue_tree_versions WHERE id = ? AND overlord_id = ?');
    $versionStmt->execute([$versionId, $overlordId]);
    $version = $versionStmt->fetch();
    if (!$version) pw_error('That published version was not found.', 404);
    $tree = pw_validate_dialogue_tree(json_decode($version['tree_json'], true));
    $encoded = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare('UPDATE overlord_dialogue_trees SET tree_json = ? WHERE overlord_id = ?');
    $stmt->execute([$encoded, $overlordId]);
} catch (Throwable $e) {
    pw_error('Could not restore this version into the draft.', 500);
}

pw_log_admin_activity('overlord_dialogue_tree_version_restored', 'Restored dialogue tree v' . (int)$version['version_number'] . ' into a draft.', $adminUser);
pw_json(['ok' => true, 'tree' => $tree, 'version' => (int)$version['version_number']]);
