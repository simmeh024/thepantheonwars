<?php
require_once __DIR__ . '/dialogue-helpers.php';

pw_require_permission('dialogues.view');
$db = pw_db();
if (!pw_dialogue_trees_ready($db)) {
    pw_error('Dialogue Tree Control needs its database migration before it can be used.', 503);
}

try {
    $rows = $db->query(
        'SELECT o.id AS overlord_id, o.slug, o.name, o.epithet,
                t.id AS tree_id, t.is_enabled, t.tree_json
         FROM overlords o
         LEFT JOIN overlord_dialogue_trees t ON t.overlord_id = o.id
         ORDER BY o.sort_order ASC, o.id ASC'
    )->fetchAll();
} catch (Throwable $e) {
    pw_error('Could not load dialogue trees.', 500);
}

$trees = array_map(function ($row) {
    $tree = $row['tree_json'] !== null ? json_decode($row['tree_json'], true) : null;
    $nodes = is_array($tree) && isset($tree['nodes']) && is_array($tree['nodes']) ? $tree['nodes'] : [];
    $branches = 0;
    foreach ($nodes as $node) {
        if (is_array($node) && isset($node['choices']) && is_array($node['choices'])) $branches += count($node['choices']);
    }
    return [
        'overlord_id' => (int)$row['overlord_id'],
        'slug' => $row['slug'],
        'name' => $row['name'],
        'epithet' => $row['epithet'],
        'is_custom' => $row['tree_id'] !== null,
        'is_enabled' => $row['tree_id'] !== null && (bool)$row['is_enabled'],
        'node_count' => count($nodes),
        'branch_count' => $branches,
    ];
}, $rows);

pw_json(['ok' => true, 'dialogues' => $trees]);
