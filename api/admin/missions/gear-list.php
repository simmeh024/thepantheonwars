<?php
require_once __DIR__ . '/missions-helpers.php';

pw_require_permission('missions.view');
$db = pw_db(); pw_admin_mission_gear_require_ready($db);
/* Every loot definition, equippable or not: the Gear tab is the only management
 * surface this table has ever had, so hiding the slotless items would leave the
 * existing salvage pool unreachable. equipped_count is how many copies players
 * are currently carrying, which is what makes an item unsafe to delete. */
$rows = $db->query(
    'SELECT l.id, l.name, l.slug, l.description, l.tier, l.slot, l.world_key, l.drop_weight,
            l.bonus_strength, l.bonus_cunning, l.bonus_science, l.bonus_charisma,
            l.required_level, l.required_role, l.icon_url, l.is_enabled, l.created_at, l.updated_at,
            (SELECT COALESCE(SUM(pl.quantity), 0) FROM game_player_loot pl WHERE pl.loot_definition_id = l.id) AS owned_count,
            (SELECT COUNT(*) FROM game_player_crew_gear g WHERE g.loot_definition_id = l.id) AS equipped_count
     FROM game_loot_definitions l
     ORDER BY l.slot ASC, FIELD(l.tier, "legendary", "rare", "uncommon", "common"), l.name ASC'
)->fetchAll();
$slots = pw_missions_gear_slots();
$rows = array_map(static function ($row) use ($slots) {
    foreach (['id', 'drop_weight', 'bonus_strength', 'bonus_cunning', 'bonus_science', 'bonus_charisma', 'required_level', 'owned_count', 'equipped_count'] as $key) {
        $row[$key] = (int)$row[$key];
    }
    $row['is_enabled'] = (bool)$row['is_enabled'];
    $row['slot_label'] = $row['slot'] !== '' && isset($slots[$row['slot']]) ? $slots[$row['slot']] : '';
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
]);
