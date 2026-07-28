<?php
/**
 * Everything the Game Tuning page needs to populate its pickers: the crew
 * roster, the item catalogue, the mission list and the research tree.
 *
 * One request rather than four. Each list is small and bounded (three roles of
 * crew, one world of missions, a research tree sized to its board), and
 * the page needs all of them before it can render anything at all.
 */
require_once __DIR__ . '/tuning-helpers.php';

pw_require_permission('game_tuning.view');
$db = pw_db();
if (!pw_tuning_ready($db)) {
    pw_error('Game Tuning needs the Missions and crew-stats migrations before it can simulate anything.', 503);
}

$gearReady = pw_mission_gear_ready($db);
$creditsReady = pw_mission_credits_ready($db);
$researchReady = pw_research_ready($db);
$contractsReady = pw_mission_overlord_contracts_ready($db);

$crew = $db->query(
    'SELECT id, name, role, tier, portrait_url, starting_level, world_affinity, is_starter
     FROM game_crew_definitions WHERE is_enabled = 1
     ORDER BY role ASC, name ASC'
)->fetchAll();
$crew = array_map(static function ($row) {
    $row['id'] = (int)$row['id'];
    $row['starting_level'] = (int)$row['starting_level'];
    $row['is_starter'] = (bool)$row['is_starter'];
    return $row;
}, $crew);

$items = [];
if ($gearReady) {
    $items = $db->query(
        'SELECT id, name, slot, tier, icon_url, required_level, required_role,
                bonus_strength, bonus_cunning, bonus_science, bonus_charisma
         FROM game_loot_definitions
         WHERE is_enabled = 1 AND slot <> ""
         ORDER BY slot ASC, required_level ASC, name ASC'
    )->fetchAll();
    $items = array_map(static function ($row) {
        foreach (['id', 'required_level', 'bonus_strength', 'bonus_cunning', 'bonus_science', 'bonus_charisma'] as $field) {
            $row[$field] = (int)$row[$field];
        }
        return $row;
    }, $items);
}

$missions = $db->query(
    'SELECT mission.id, mission.name, mission.mission_type, mission.duration_seconds,
            mission.min_crew, mission.max_crew, mission.xp_reward, mission.reputation_reward,
            mission.base_success_percent, mission.loot_rolls, mission.is_enabled'
    . ($creditsReady ? ', mission.credit_reward' : ', 0 AS credit_reward')
    . ($contractsReady ? ', mission.overlord_id, overlord.name AS overlord_name' : ', NULL AS overlord_id, NULL AS overlord_name') . '
     FROM game_mission_definitions mission'
    . ($contractsReady ? ' LEFT JOIN overlords overlord ON overlord.id = mission.overlord_id' : '') . '
     ORDER BY mission.sort_order ASC, mission.id ASC'
)->fetchAll();
$missions = array_map(static function ($row) {
    foreach (['id', 'duration_seconds', 'min_crew', 'max_crew', 'xp_reward', 'reputation_reward', 'base_success_percent', 'loot_rolls', 'credit_reward'] as $field) {
        $row[$field] = (int)$row[$field];
    }
    $row['is_enabled'] = (bool)$row['is_enabled'];
    $row['overlord_id'] = $row['overlord_id'] !== null ? (int)$row['overlord_id'] : null;
    return $row;
}, $missions);

$research = [];
if ($researchReady) {
    $research = $db->query(
        'SELECT n.id, n.name, n.effect_type, n.effect_value, n.required_reputation_level, n.credit_cost,
                c.name AS category_name
         FROM game_research_nodes n
         LEFT JOIN game_research_categories c ON c.id = n.research_category_id
         WHERE n.is_enabled = 1
         ORDER BY c.sort_order ASC, n.sort_order ASC, n.id ASC'
    )->fetchAll();
    $research = array_map(static function ($row) {
        $row['id'] = (int)$row['id'];
        $row['effect_value'] = (float)$row['effect_value'];
        $row['required_reputation_level'] = (int)$row['required_reputation_level'];
        $row['credit_cost'] = (int)$row['credit_cost'];
        return $row;
    }, $research);
}

pw_json([
    'ok' => true,
    'crew' => $crew,
    'items' => $items,
    'missions' => $missions,
    'research' => $research,
    'effect_types' => pw_research_effect_types(),
    'metrics' => pw_tuning_metrics(),
    // Generated from the engine's own constants -- see pw_missions_stat_reference().
    'stat_reference' => pw_missions_stat_reference(),
    /* The rarity ladder, read from the engine rather than restated: a crew
     * member's rarity scales its stats, raises its role bonus and prices a
     * duplicate award, and a balance tool that did not show those three
     * would be comparing recruits on level alone. */
    'crew_tiers' => pw_missions_crew_tier_profile(),
    'role_rates' => pw_missions_role_rates(),
    'gear_slots' => pw_missions_gear_slots(),
    // The same endgame role ceilings the player-facing MAXED badge uses.
    'item_level_ceilings' => pw_missions_item_level_role_ceilings($db),
    'max_level' => PW_MISSION_MAX_LEVEL,
    'max_points' => PW_TUNING_MAX_POINTS,
    'gear_ready' => $gearReady,
    'research_ready' => $researchReady,
    'scenarios_ready' => pw_tuning_scenarios_ready($db),
]);
