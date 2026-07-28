<?php
require_once __DIR__ . '/missions-helpers.php';

pw_require_permission('missions.view');
$db = pw_db(); pw_admin_mission_gear_require_ready($db);
/* Every loot definition, equippable or not: the Gear tab is the only management
 * surface this table has ever had, so hiding the slotless items would leave the
 * existing salvage pool unreachable. equipped_count is how many copies players
 * are currently carrying, which is what makes an item unsafe to delete. */
$stimsReady = pw_mission_stims_ready($db);
$itemLevelsReady = pw_mission_item_levels_ready($db);
$fieldGradeReady = pw_mission_field_grade_ready($db);
$rows = $db->query(
    'SELECT l.id, l.name, l.slug, l.description, l.tier, l.slot, l.world_key, l.drop_weight,
            l.bonus_strength, l.bonus_cunning, l.bonus_science, l.bonus_charisma,
            l.required_level, l.required_role, l.icon_url,'
    . ($itemLevelsReady ? ' l.item_level,' : ' 0 AS item_level,')
    . ($fieldGradeReady ? ' l.field_grade,' : ' 0 AS field_grade,') . ' l.is_enabled, l.created_at, l.updated_at,'
    . ($stimsReady ? ' l.stim_effect, l.stim_value, l.stim_duration_seconds,' : ' "" AS stim_effect, 0 AS stim_value, 0 AS stim_duration_seconds,') . '
            (SELECT COALESCE(SUM(pl.quantity), 0) FROM game_player_loot pl WHERE pl.loot_definition_id = l.id) AS owned_count,
            (SELECT COUNT(*) FROM game_player_crew_gear g WHERE g.loot_definition_id = l.id) AS equipped_count
     FROM game_loot_definitions l
     ORDER BY l.slot ASC, FIELD(l.tier, "legendary", "rare", "uncommon", "common"), l.name ASC'
)->fetchAll();
$slots = pw_missions_gear_slots();
$rows = array_map(static function ($row) use ($slots, $stimsReady) {
    foreach (['id', 'drop_weight', 'bonus_strength', 'bonus_cunning', 'bonus_science', 'bonus_charisma', 'required_level', 'item_level', 'field_grade', 'owned_count', 'equipped_count'] as $key) {
        $row[$key] = (int)$row[$key];
    }
    $row['is_enabled'] = (bool)$row['is_enabled'];
    $row['slot_label'] = $row['slot'] !== '' && isset($slots[$row['slot']]) ? $slots[$row['slot']] : '';
    $row['stim_effect'] = $stimsReady ? (string)$row['stim_effect'] : '';
    $row['stim_value'] = $stimsReady ? (float)$row['stim_value'] : 0.0;
    $row['stim_duration_seconds'] = $stimsReady ? (int)$row['stim_duration_seconds'] : 0;
    // The same classifier the player-facing inventory uses, so the admin list
    // and the panel it feeds can never label an item differently.
    $row['category'] = pw_missions_inventory_category($row);
    return $row;
}, $rows);
pw_json([
    'ok' => true,
    'gear' => $rows,
    'slots' => array_map(static function ($key, $label) { return ['key' => $key, 'label' => $label]; }, array_keys($slots), array_values($slots)),
    'tiers' => pw_missions_loot_tiers(),
    'roles' => array_keys(pw_missions_role_rates()),
    'max_stat' => PW_MISSION_MAX_STAT,
    'max_gear_stat' => PW_MISSION_MAX_GEAR_STAT,
    'item_levels_ready' => $itemLevelsReady,
    'field_grade_ready' => $fieldGradeReady,
    'stims_ready' => $stimsReady,
    'stim_effect_types' => pw_missions_stim_effect_types(),
]);
