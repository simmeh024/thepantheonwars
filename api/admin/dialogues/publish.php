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
if ($encoded === false || strlen($encoded) > 60000) pw_error('This dialogue tree is too large to publish.');
$enabled = !empty($input['is_enabled']) ? 1 : 0;
$db = pw_db();
if (!pw_dialogue_trees_ready($db) || !pw_dialogue_tree_publish_ready($db) || !pw_dialogue_tree_versions_ready($db)) {
    pw_error('The Dialogue Editor Workflow migration still needs to be run.', 503);
}

try {
    $db->beginTransaction();
    $overlordStmt = $db->prepare('SELECT name FROM overlords WHERE id = ?');
    $overlordStmt->execute([$overlordId]);
    $overlord = $overlordStmt->fetch();
    if (!$overlord) pw_error('Overlord not found.', 404);

    $currentStmt = $db->prepare('SELECT published_version FROM overlord_dialogue_trees WHERE overlord_id = ? FOR UPDATE');
    $currentStmt->execute([$overlordId]);
    $current = $currentStmt->fetch();
    $nextVersion = $current ? ((int)$current['published_version'] + 1) : 1;
    $treeStmt = $db->prepare(
        'INSERT INTO overlord_dialogue_trees (overlord_id, is_enabled, draft_is_enabled, tree_json, published_tree_json, published_version, published_at)
         VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), draft_is_enabled = VALUES(draft_is_enabled), tree_json = VALUES(tree_json), published_tree_json = VALUES(published_tree_json), published_version = VALUES(published_version), published_at = UTC_TIMESTAMP()'
    );
    $treeStmt->execute([$overlordId, $enabled, $enabled, $encoded, $encoded, $nextVersion]);
    $versionStmt = $db->prepare('INSERT INTO overlord_dialogue_tree_versions (overlord_id, version_number, tree_json, created_by) VALUES (?, ?, ?, ?)');
    $versionStmt->execute([$overlordId, $nextVersion, $encoded, (int)$adminUser['id']]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error('Could not publish this dialogue tree.', 500);
}

pw_log_admin_activity('overlord_dialogue_tree_published', 'Published dialogue tree v' . $nextVersion . ' for Overlord "' . $overlord['name'] . '"' . ($enabled ? '.' : ' (disabled).'), $adminUser);
pw_json(['ok' => true, 'version' => $nextVersion]);
