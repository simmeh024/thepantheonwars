<?php
require_once __DIR__ . '/dialogue-helpers.php';

pw_require_permission('dialogues.view');
$db = pw_db();
if (!pw_dialogue_trees_ready($db)) {
    pw_error('Dialogue Tree Control needs its database migration before it can be used.', 503);
}

$overlordId = isset($_GET['overlord_id']) ? (int)$_GET['overlord_id'] : 0;
if ($overlordId <= 0) pw_error('Missing Overlord.');

try {
    $stmt = $db->prepare('SELECT id, slug, name, epithet FROM overlords WHERE id = ?');
    $stmt->execute([$overlordId]);
    $overlord = $stmt->fetch();
    if (!$overlord) pw_error('Overlord not found.', 404);

    $treeStmt = $db->prepare('SELECT is_enabled, tree_json FROM overlord_dialogue_trees WHERE overlord_id = ?');
    $treeStmt->execute([$overlordId]);
    $stored = $treeStmt->fetch();
    $transmission = [];
    try {
        if ($db->query("SHOW TABLES LIKE 'overlord_transmissions'")->fetch()) {
            $transmissionStmt = $db->prepare('SELECT opening_message, followup_message FROM overlord_transmissions WHERE overlord_id = ?');
            $transmissionStmt->execute([$overlordId]);
            $transmission = $transmissionStmt->fetch() ?: [];
        }
    } catch (Throwable $e) {}
} catch (Throwable $e) {
    pw_error('Could not load this dialogue tree.', 500);
}

$tree = $stored ? json_decode($stored['tree_json'], true) : pw_dialogue_default_tree($transmission);
if (!is_array($tree)) $tree = pw_dialogue_default_tree($transmission);

pw_json([
    'ok' => true,
    'overlord' => ['id' => (int)$overlord['id'], 'slug' => $overlord['slug'], 'name' => $overlord['name'], 'epithet' => $overlord['epithet']],
    'is_custom' => (bool)$stored,
    'is_enabled' => $stored ? (bool)$stored['is_enabled'] : true,
    'tree' => $tree,
]);
