<?php
require_once __DIR__ . '/dialogue-helpers.php';

pw_require_permission('dialogues.view');
$db = pw_db();
if (!pw_dialogue_trees_ready($db)) {
    pw_error('Dialogue Tree Control needs its database migration before it can be used.', 503);
}
$publishReady = pw_dialogue_tree_publish_ready($db);

$overlordId = isset($_GET['overlord_id']) ? (int)$_GET['overlord_id'] : 0;
if ($overlordId <= 0) pw_error('Missing Overlord.');

try {
    $stmt = $db->prepare('SELECT id, slug, name, epithet FROM overlords WHERE id = ?');
    $stmt->execute([$overlordId]);
    $overlord = $stmt->fetch();
    if (!$overlord) pw_error('Overlord not found.', 404);

    $treeStmt = $db->prepare(
        $publishReady
            ? 'SELECT is_enabled, draft_is_enabled, tree_json, published_tree_json, published_version, published_at FROM overlord_dialogue_trees WHERE overlord_id = ?'
            : 'SELECT is_enabled, tree_json FROM overlord_dialogue_trees WHERE overlord_id = ?'
    );
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
$publishedTree = $publishReady && $stored && !empty($stored['published_tree_json'])
    ? json_decode($stored['published_tree_json'], true) : null;
if (!is_array($publishedTree)) $publishedTree = null;
$versions = [];
if ($publishReady && pw_dialogue_tree_versions_ready($db)) {
    $versionStmt = $db->prepare(
        'SELECT v.id, v.version_number, v.created_at, u.username
         FROM overlord_dialogue_tree_versions v
         LEFT JOIN users u ON u.id = v.created_by
         WHERE v.overlord_id = ? ORDER BY v.version_number DESC LIMIT 20'
    );
    $versionStmt->execute([$overlordId]);
    $versions = array_map(static function ($row) {
        return [
            'id' => (int)$row['id'],
            'version_number' => (int)$row['version_number'],
            'created_at' => $row['created_at'],
            'username' => $row['username'],
        ];
    }, $versionStmt->fetchAll());
}

pw_json([
    'ok' => true,
    'overlord' => ['id' => (int)$overlord['id'], 'slug' => $overlord['slug'], 'name' => $overlord['name'], 'epithet' => $overlord['epithet']],
    'is_custom' => (bool)$stored,
    'is_enabled' => $stored ? (bool)$stored['is_enabled'] : true,
    'draft_is_enabled' => $publishReady && $stored ? (bool)$stored['draft_is_enabled'] : ($stored ? (bool)$stored['is_enabled'] : true),
    'publish_ready' => $publishReady,
    'published_tree' => $publishedTree,
    'published_version' => $publishReady && $stored ? (int)$stored['published_version'] : 0,
    'published_at' => $publishReady && $stored ? $stored['published_at'] : null,
    'versions' => $versions,
    'tree' => $tree,
]);
