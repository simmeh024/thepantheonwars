<?php
require_once __DIR__ . '/loot-helpers.php';

pw_require_permission('loot_tables.view');
$db = pw_db();
pw_missions_require_loot_table_gear_ready($db);
$researchLocksReady = pw_mission_loot_table_research_locks_ready($db);

$tables = $db->query(
    'SELECT lt.id, lt.name, lt.slug, lt.description, lt.is_enabled, '
    . (pw_mission_loot_table_sweep_flag_ready($db) ? 'lt.is_sweep_only, ' : '0 AS is_sweep_only, ')
    . ($researchLocksReady ? 'lt.is_research_rare, lt.requires_research_unlock, ' : '0 AS is_research_rare, 0 AS requires_research_unlock, ')
    . 'lt.created_at, lt.updated_at,
            (SELECT COUNT(*) FROM game_mission_loot_tables link WHERE link.loot_table_id = lt.id) AS mission_count
     FROM game_loot_tables lt
     ORDER BY lt.name ASC, lt.id ASC'
)->fetchAll();

$entryRows = $db->query(
    'SELECT entry.id, entry.loot_table_id, entry.entry_type, entry.crew_definition_id, entry.loot_definition_id,
            entry.chance_percent, entry.sort_order,
            crew.name AS crew_name, crew.role, crew.portrait_url, crew.is_enabled AS crew_enabled,
            gear.name AS gear_name, gear.slug AS gear_slug, gear.tier AS gear_tier, gear.slot AS gear_slot,
            gear.icon_url AS gear_icon_url, gear.is_enabled AS gear_enabled
     FROM game_loot_table_entries entry
     LEFT JOIN game_crew_definitions crew ON crew.id = entry.crew_definition_id
     LEFT JOIN game_loot_definitions gear ON gear.id = entry.loot_definition_id
     ORDER BY entry.loot_table_id ASC, entry.sort_order ASC, entry.id ASC'
)->fetchAll();

$entriesByTable = [];
foreach ($entryRows as $row) {
    $entriesByTable[(int)$row['loot_table_id']][] = [
        'id' => (int)$row['id'],
        'entry_type' => $row['entry_type'] === 'gear' ? 'gear' : 'crew',
        'definition_id' => $row['entry_type'] === 'gear' ? (int)$row['loot_definition_id'] : (int)$row['crew_definition_id'],
        'chance_percent' => (float)$row['chance_percent'],
        'sort_order' => (int)$row['sort_order'],
        'name' => $row['entry_type'] === 'gear' ? $row['gear_name'] : $row['crew_name'],
        'role' => $row['role'],
        'portrait_url' => $row['portrait_url'],
        'crew_enabled' => (bool)$row['crew_enabled'],
        'tier' => $row['gear_tier'],
        'slot' => $row['gear_slot'],
        'icon_url' => $row['gear_icon_url'],
        'gear_enabled' => (bool)$row['gear_enabled'],
    ];
}

$tables = array_map(static function ($row) use ($entriesByTable) {
    $row['id'] = (int)$row['id'];
    $row['is_enabled'] = (bool)$row['is_enabled'];
    $row['is_research_rare'] = (bool)$row['is_research_rare'];
    $row['is_sweep_only'] = (bool)$row['is_sweep_only'];
    $row['requires_research_unlock'] = (bool)$row['requires_research_unlock'];
    $row['mission_count'] = (int)$row['mission_count'];
    $row['entries'] = $entriesByTable[$row['id']] ?? [];
    return $row;
}, $tables);

// The character catalogue the entry picker chooses from. Disabled definitions
// are included and flagged rather than dropped, so an existing entry pointing at
// one is still editable instead of silently vanishing from its own table.
$crew = array_map(static function ($row) {
    $row['id'] = (int)$row['id'];
    $row['is_enabled'] = (bool)$row['is_enabled'];
    return $row;
}, $db->query(
    'SELECT id, name, slug, role, portrait_url, world_affinity, is_enabled
     FROM game_crew_definitions ORDER BY name ASC, id ASC'
)->fetchAll());

/* Every loot definition, whatever kind. This used to be equipment only, on the
 * reasoning that salvage belongs in the weighted world pool -- but that pool is
 * per world and untargetable, so there was no way to author a specific
 * component as the reward for a specific operation, which is exactly what a
 * loot table is for. Stims arrived with the same problem.
 *
 * The entry_type stays "gear" for all three: it distinguishes an item award
 * from a character award, and every item follows the identical grant path
 * through pw_missions_store_loot(). A third entry type would be a second name
 * for the same behaviour. */
$stimsReady = pw_mission_stims_ready($db);
/* The reader-facing slot name, resolved here rather than mapped again in the
 * browser -- api/admin/missions/gear-list.php already sends the same field from
 * the same helper, and a second copy of the seven names would drift. */
$gearSlots = pw_missions_gear_slots();
$gear = array_map(static function ($row) use ($stimsReady, $gearSlots) {
    $row['id'] = (int)$row['id'];
    $row['is_enabled'] = (bool)$row['is_enabled'];
    $row['slot_label'] = $row['slot'] !== '' && isset($gearSlots[$row['slot']]) ? $gearSlots[$row['slot']] : '';
    $row['stim_effect'] = $stimsReady ? (string)$row['stim_effect'] : '';
    // The same classifier the player's inventory reads, so the picker can group
    // by kind without deciding for itself what an item is.
    $row['category'] = pw_missions_inventory_category($row);
    return $row;
}, $db->query(
    'SELECT id, name, slug, tier, slot, icon_url, is_enabled,'
    . ($stimsReady ? ' stim_effect' : ' "" AS stim_effect') . '
     FROM game_loot_definitions
     ORDER BY tier ASC, name ASC, id ASC'
)->fetchAll());

// Mission attachments, so one screen shows which missions open which table.
$missions = array_map(static function ($row) {
    $row['id'] = (int)$row['id'];
    $row['is_enabled'] = (bool)$row['is_enabled'];
    $row['links'] = [];
    return $row;
}, $db->query(
    'SELECT id, name, slug, world_key, is_enabled FROM game_mission_definitions ORDER BY sort_order ASC, id ASC'
)->fetchAll());
$missionsById = [];
foreach ($missions as $index => $mission) $missionsById[$mission['id']] = $index;
foreach ($db->query(
    'SELECT mission_definition_id, loot_table_id, chance_percent, sort_order
     FROM game_mission_loot_tables ORDER BY sort_order ASC, id ASC'
)->fetchAll() as $row) {
    $missionId = (int)$row['mission_definition_id'];
    if (!isset($missionsById[$missionId])) continue;
    $missions[$missionsById[$missionId]]['links'][] = [
        'loot_table_id' => (int)$row['loot_table_id'],
        'chance_percent' => (float)$row['chance_percent'],
    ];
}

pw_json(['ok' => true, 'tables' => $tables, 'crew' => $crew, 'gear' => $gear, 'missions' => $missions, 'research_locks_ready' => $researchLocksReady]);
