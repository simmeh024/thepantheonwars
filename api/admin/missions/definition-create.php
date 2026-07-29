<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('missions.edit');
$input = pw_input(); pw_require_csrf($input);
$db = pw_db(); pw_admin_mission_successions_require_ready($db);
$data = pw_admin_mission_definition_input($input);
$duplicate = $db->prepare('SELECT id FROM game_mission_definitions WHERE slug = ?'); $duplicate->execute([$data['slug']]);
if ($duplicate->fetch()) pw_error('A mission with that slug already exists.', 409);
pw_admin_validate_mission_succession($db, null, $data);
$campaignReady = pw_mission_campaign_ready($db);
$statsReady = pw_mission_stats_ready($db);
$creditsReady = pw_mission_credits_ready($db);
$watermarkReady = pw_mission_watermark_ready($db);
$researchLocksReady = pw_mission_research_locks_ready($db);
$progressionReady = pw_mission_contract_progression_ready($db);
/* Column list and value list are built from one array of pairs so the
 * placeholder count can never drift from the value count as further optional
 * migrations are added -- PDO only reports that mismatch at execute() against a
 * live database, which makes it invisible to any static check here. */
$columns = [
    'world_key' => $data['world_key'], 'name' => $data['name'], 'slug' => $data['slug'],
    'description' => $data['description'], 'mission_type' => $data['mission_type'],
    'duration_seconds' => $data['duration_seconds'], 'min_crew' => $data['min_crew'], 'max_crew' => $data['max_crew'],
    'xp_reward' => $data['xp_reward'], 'reputation_reward' => $data['reputation_reward'],
    'is_enabled' => $data['is_enabled'], 'sort_order' => $data['sort_order'],
    'unlocks_after_mission_id' => $data['unlocks_after_mission_id'],
    'unlocks_after_completion_count' => $data['unlocks_after_completion_count'],
];
if ($campaignReady) $columns['is_campaign_final'] = $data['is_campaign_final'];
if ($statsReady) { $columns['base_success_percent'] = $data['base_success_percent']; $columns['loot_rolls'] = $data['loot_rolls']; }
if ($creditsReady) $columns['credit_reward'] = $data['credit_reward'];
if ($watermarkReady) { $columns['watermark_url'] = $data['watermark_url']; $columns['watermark_opacity'] = $data['watermark_opacity']; }
if ($researchLocksReady) $columns['requires_research_unlock'] = $data['requires_research_unlock'];
if ($progressionReady) {
    $columns['contract_tier'] = $data['contract_tier'];
    $columns['recommended_item_level'] = $data['recommended_item_level'];
    $columns['reward_item_level_min'] = $data['reward_item_level_min'];
    $columns['reward_item_level_max'] = $data['reward_item_level_max'];
    $columns['featured_slots'] = $data['featured_slots'];
}
if (pw_mission_overlord_contracts_ready($db)) $columns['overlord_id'] = $data['overlord_id'];
if (pw_mission_overlord_standing_ready($db)) $columns['overlord_standing_reward'] = $data['overlord_standing_reward'];
if (pw_mission_contested_contracts_ready($db)) {
    $columns['is_contested'] = $data['is_contested'];
    $columns['rival_faction_name'] = $data['rival_faction_name'];
}
if (pw_mission_salvage_recovery_contracts_ready($db)) $columns['is_salvage_recovery_contract'] = $data['is_salvage_recovery_contract'];
if (pw_mission_overlord_clearances_ready($db)) $columns['requires_overlord_clearance'] = $data['requires_overlord_clearance'];
$stmt = $db->prepare(
    'INSERT INTO game_mission_definitions (' . implode(', ', array_keys($columns)) . ')'
    . ' VALUES (' . pw_missions_placeholders(count($columns)) . ')'
);
$stmt->execute(array_values($columns));
$id = (int)$db->lastInsertId();
if ($campaignReady) pw_admin_apply_campaign_final($db, $id, $data);
pw_log_admin_activity('mission_definition_created', 'Created mission definition "' . $data['name'] . '".', $admin);
pw_json(['ok' => true, 'id' => $id]);
