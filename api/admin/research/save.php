<?php
require_once __DIR__ . '/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('research.manage');
$input = pw_input();
pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$id = $id === false ? null : $id;
$db = pw_db();
pw_admin_research_require_ready($db);

try {
    $db->beginTransaction();
    if ($id !== null) {
        $existing = $db->prepare('SELECT id FROM game_research_nodes WHERE id = ? FOR UPDATE');
        $existing->execute([$id]);
        if (!$existing->fetch()) throw new RuntimeException('Research protocol not found.');
    }
    $data = pw_admin_research_node_input($db, $input);
    $prerequisites = pw_admin_research_prerequisites($db, $id, $input['prerequisite_ids'] ?? []);
    if ($id === null) {
        $insert = $db->prepare(
            'INSERT INTO game_research_nodes (name, slug, description, image_url, research_category_id, effect_type, effect_value, target_mission_definition_id, required_reputation_level, credit_cost, salvage_loot_definition_id, salvage_quantity, canvas_x, canvas_y, sort_order, is_enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute(array_values($data));
        $id = (int)$db->lastInsertId();
        $action = 'research_node_created';
        $verb = 'Created';
    } else {
        $update = $db->prepare(
            'UPDATE game_research_nodes SET name = ?, slug = ?, description = ?, image_url = ?, research_category_id = ?, effect_type = ?, effect_value = ?, target_mission_definition_id = ?, required_reputation_level = ?, credit_cost = ?, salvage_loot_definition_id = ?, salvage_quantity = ?, canvas_x = ?, canvas_y = ?, sort_order = ?, is_enabled = ? WHERE id = ?'
        );
        $update->execute(array_merge(array_values($data), [$id]));
        $action = 'research_node_updated';
        $verb = 'Updated';
    }
    $db->prepare('DELETE FROM game_research_prerequisites WHERE research_node_id = ?')->execute([$id]);
    if ($prerequisites) {
        $link = $db->prepare('INSERT INTO game_research_prerequisites (research_node_id, prerequisite_node_id) VALUES (?, ?)');
        foreach ($prerequisites as $prerequisiteId) $link->execute([$id, $prerequisiteId]);
    }
    $db->commit();
    pw_log_admin_activity($action, $verb . ' research protocol "' . $data['name'] . '".', $admin);
    pw_json(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not save this research protocol. Check that the slug and secret mission are unique.', 409);
}
