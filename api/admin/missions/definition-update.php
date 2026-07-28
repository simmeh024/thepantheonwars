<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('missions.edit');
$input = pw_input(); pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id < 1) pw_error('Missing mission definition.');
$db = pw_db(); pw_admin_mission_successions_require_ready($db);
$data = pw_admin_mission_definition_input($input);
$existing = $db->prepare('SELECT id FROM game_mission_definitions WHERE id = ?'); $existing->execute([$id]);
if (!$existing->fetch()) pw_error('Mission definition not found.', 404);
$duplicate = $db->prepare('SELECT id FROM game_mission_definitions WHERE slug = ? AND id != ?'); $duplicate->execute([$data['slug'], $id]);
if ($duplicate->fetch()) pw_error('A mission with that slug already exists.', 409);
pw_admin_validate_mission_succession($db, (int)$id, $data);
$campaignReady = pw_mission_campaign_ready($db);
$statsReady = pw_mission_stats_ready($db);
$creditsReady = pw_mission_credits_ready($db);
$watermarkReady = pw_mission_watermark_ready($db);
$researchLocksReady = pw_mission_research_locks_ready($db);
$progressionReady = pw_mission_contract_progression_ready($db);
// Same one-array rule as definition-create.php: the SET clause and its values
// come from a single source so they cannot drift apart.
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
if (pw_mission_contested_contracts_ready($db)) {
    $columns['is_contested'] = $data['is_contested'];
    $columns['rival_faction_name'] = $data['rival_faction_name'];
}
if (pw_mission_salvage_recovery_contracts_ready($db)) $columns['is_salvage_recovery_contract'] = $data['is_salvage_recovery_contract'];
if (pw_mission_overlord_clearances_ready($db)) $columns['requires_overlord_clearance'] = $data['requires_overlord_clearance'];
$assignments = array_map(static function ($column) { return $column . ' = ?'; }, array_keys($columns));
$stmt = $db->prepare('UPDATE game_mission_definitions SET ' . implode(', ', $assignments) . ' WHERE id = ?');
$values = array_values($columns);
$values[] = $id;
$stmt->execute($values);
if ($campaignReady) pw_admin_apply_campaign_final($db, (int)$id, $data);
pw_log_admin_activity('mission_definition_updated', 'Updated mission definition "' . $data['name'] . '".', $admin);
pw_json(['ok' => true]);
