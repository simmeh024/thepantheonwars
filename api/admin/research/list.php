<?php
require_once __DIR__ . '/research-helpers.php';

pw_require_permission('research.view');
$db = pw_db();
pw_admin_research_require_ready($db);

try {
    $nodes = $db->query(
        'SELECT n.*, category.name AS category_name, category.slug AS category_slug,
                target.name AS target_mission_name, salvage.name AS salvage_name
         FROM game_research_nodes n
         LEFT JOIN game_research_categories category ON category.id = n.research_category_id
         LEFT JOIN game_mission_definitions target ON target.id = n.target_mission_definition_id
         LEFT JOIN game_loot_definitions salvage ON salvage.id = n.salvage_loot_definition_id
         ORDER BY n.sort_order ASC, n.id ASC'
    )->fetchAll();
    $links = $db->query('SELECT research_node_id, prerequisite_node_id FROM game_research_prerequisites ORDER BY research_node_id ASC, prerequisite_node_id ASC')->fetchAll();
    $byNode = [];
    foreach ($links as $link) $byNode[(int)$link['research_node_id']][] = (int)$link['prerequisite_node_id'];
    foreach ($nodes as &$node) {
        foreach (['id', 'research_category_id', 'target_mission_definition_id', 'required_reputation_level', 'credit_cost', 'salvage_loot_definition_id', 'salvage_quantity', 'canvas_x', 'canvas_y', 'sort_order'] as $field) {
            if ($node[$field] !== null) $node[$field] = (int)$node[$field];
        }
        $node['effect_value'] = (float)$node['effect_value'];
        $node['is_enabled'] = (bool)$node['is_enabled'];
        $node['image_url'] = pw_research_image_url($node['image_url']);
        $node['prerequisite_ids'] = $byNode[(int)$node['id']] ?? [];
    }
    unset($node);
    $categories = $db->query('SELECT id, name, slug, description, sort_order FROM game_research_categories ORDER BY sort_order ASC, id ASC')->fetchAll();
    foreach ($categories as &$category) {
        $category['id'] = (int)$category['id'];
        $category['sort_order'] = (int)$category['sort_order'];
    }
    unset($category);
    $salvage = $db->query('SELECT id, name, tier, icon_url FROM game_loot_definitions WHERE slot = "" ORDER BY name ASC')->fetchAll();
    foreach ($salvage as &$item) { $item['id'] = (int)$item['id']; $item['icon_url'] = pw_missions_gear_icon_url($item['icon_url']); }
    unset($item);
    $missions = $db->query('SELECT id, name, mission_type, is_enabled FROM game_mission_definitions WHERE world_key = "neoh" ORDER BY sort_order ASC, id ASC')->fetchAll();
    foreach ($missions as &$mission) { $mission['id'] = (int)$mission['id']; $mission['is_enabled'] = (bool)$mission['is_enabled']; }
    unset($mission);
    pw_json(['ok' => true, 'nodes' => $nodes, 'categories' => $categories, 'salvage' => $salvage, 'missions' => $missions, 'effect_types' => pw_research_effect_types(), 'board' => ['width' => PW_RESEARCH_BOARD_WIDTH, 'height' => PW_RESEARCH_BOARD_HEIGHT]]);
} catch (Throwable $e) {
    pw_error('Could not load Research Management. Confirm that the research migrations have been run.', 503);
}
