<?php
require_once __DIR__ . '/loot-helpers.php';

pw_require_permission('loot_tables.view');
$db = pw_db();
pw_missions_require_loot_tables_ready($db);

$tables = $db->query(
    'SELECT lt.id, lt.name, lt.slug, lt.description, lt.is_enabled, lt.created_at, lt.updated_at,
            (SELECT COUNT(*) FROM game_mission_loot_tables link WHERE link.loot_table_id = lt.id) AS mission_count
     FROM game_loot_tables lt
     ORDER BY lt.name ASC, lt.id ASC'
)->fetchAll();

$entryRows = $db->query(
    'SELECT entry.id, entry.loot_table_id, entry.crew_definition_id, entry.chance_percent, entry.sort_order,
            crew.name, crew.role, crew.portrait_url, crew.is_enabled AS crew_enabled
     FROM game_loot_table_entries entry
     LEFT JOIN game_crew_definitions crew ON crew.id = entry.crew_definition_id
     ORDER BY entry.loot_table_id ASC, entry.sort_order ASC, entry.id ASC'
)->fetchAll();

$entriesByTable = [];
foreach ($entryRows as $row) {
    $entriesByTable[(int)$row['loot_table_id']][] = [
        'id' => (int)$row['id'],
        'crew_definition_id' => (int)$row['crew_definition_id'],
        'chance_percent' => (float)$row['chance_percent'],
        'sort_order' => (int)$row['sort_order'],
        'name' => $row['name'],
        'role' => $row['role'],
        'portrait_url' => $row['portrait_url'],
        'crew_enabled' => (bool)$row['crew_enabled'],
    ];
}

$tables = array_map(static function ($row) use ($entriesByTable) {
    $row['id'] = (int)$row['id'];
    $row['is_enabled'] = (bool)$row['is_enabled'];
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

pw_json(['ok' => true, 'tables' => $tables, 'crew' => $crew, 'missions' => $missions]);
