<?php
require_once __DIR__ . '/missions-helpers.php';

pw_require_permission('missions.view');
$db = pw_db();
pw_admin_mission_successions_require_ready($db);
$campaignReady = pw_mission_campaign_ready($db);
$statsReady = pw_mission_stats_ready($db);
$creditsReady = pw_mission_credits_ready($db);
$watermarkReady = pw_mission_watermark_ready($db);
$researchLocksReady = pw_mission_research_locks_ready($db);
$contractsReady = pw_mission_overlord_contracts_ready($db);
$contestedContractsReady = pw_mission_contested_contracts_ready($db);
$rows = $db->query(
    'SELECT mission.id, mission.world_key, mission.name, mission.slug, mission.description, mission.mission_type,
            mission.duration_seconds, mission.min_crew, mission.max_crew, mission.xp_reward, mission.reputation_reward,
            mission.is_enabled, mission.sort_order, mission.unlocks_after_mission_id, mission.unlocks_after_completion_count,'
    . ($campaignReady ? ' mission.is_campaign_final,' : ' 0 AS is_campaign_final,')
    . ($creditsReady ? ' mission.credit_reward,' : ' 0 AS credit_reward,')
    . ($watermarkReady ? ' mission.watermark_url, mission.watermark_opacity,' : ' "" AS watermark_url, 10 AS watermark_opacity,')
    . ($statsReady ? ' mission.base_success_percent, mission.loot_rolls,' : ' 100 AS base_success_percent, 0 AS loot_rolls,') .
    ($researchLocksReady ? ' mission.requires_research_unlock,' : ' 0 AS requires_research_unlock,')
    . ($contractsReady ? ' mission.overlord_id, overlord.name AS overlord_name,' : ' NULL AS overlord_id, NULL AS overlord_name,') .
    ($contestedContractsReady ? ' mission.is_contested, mission.rival_faction_name,' : ' 0 AS is_contested, "" AS rival_faction_name,') .
           ' prerequisite.name AS unlocks_after_mission_name, mission.created_at, mission.updated_at
     FROM game_mission_definitions mission
     LEFT JOIN game_mission_definitions prerequisite ON prerequisite.id = mission.unlocks_after_mission_id'
    /* Joined only once the column exists. The overlords table is always there,
     * but a join predicate naming a missing column is a hard SQL error rather
     * than a NULL, which would take the whole mission list down before the
     * migration has been run. */
    . ($contractsReady ? ' LEFT JOIN overlords overlord ON overlord.id = mission.overlord_id' : '') . '
     ORDER BY mission.world_key ASC, mission.sort_order ASC, mission.id ASC'
)->fetchAll();
$rows = array_map(static function ($row) {
    foreach (['id', 'duration_seconds', 'min_crew', 'max_crew', 'xp_reward', 'reputation_reward', 'credit_reward', 'sort_order', 'unlocks_after_completion_count', 'base_success_percent', 'loot_rolls', 'watermark_opacity'] as $field) $row[$field] = (int)$row[$field];
    $row['unlocks_after_mission_id'] = $row['unlocks_after_mission_id'] !== null ? (int)$row['unlocks_after_mission_id'] : null;
    $row['is_enabled'] = (bool)$row['is_enabled'];
    $row['is_campaign_final'] = (bool)$row['is_campaign_final'];
    $row['requires_research_unlock'] = (bool)$row['requires_research_unlock'];
    $row['overlord_id'] = $row['overlord_id'] !== null ? (int)$row['overlord_id'] : null;
    $row['is_contested'] = !empty($row['is_contested']);
    $row['rival_faction_name'] = $row['is_contested'] ? pw_missions_contested_contract_faction($row['rival_faction_name']) : '';
    return $row;
}, $rows);
/* The roster powers the editor's Overlord picker. Sent with the list rather
 * than fetched separately: it is six rows and the editor always needs it. */
$overlords = $contractsReady
    ? $db->query('SELECT id, name, epithet FROM overlords ORDER BY sort_order ASC, name ASC')->fetchAll()
    : [];
$overlords = array_map(static function ($row) { $row['id'] = (int)$row['id']; return $row; }, $overlords);
pw_json(['ok' => true, 'missions' => $rows, 'campaign_ready' => $campaignReady, 'contracts_ready' => $contractsReady, 'contested_contracts_ready' => $contestedContractsReady, 'overlords' => $overlords, 'contract_rank' => PW_MISSION_OVERLORD_CONTRACT_RANK]);
