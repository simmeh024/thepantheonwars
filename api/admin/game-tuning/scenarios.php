<?php
/**
 * Saved tuning scenarios: list, save and delete in one endpoint.
 *
 * A scenario is a bundle of INPUTS -- which crew member, which items, which
 * research, which operations -- and never a stored result. A balance pass is
 * iterative: change an item, come back, re-run the same comparison. Storing the
 * numbers instead would mean a reloaded scenario showed the old ones.
 *
 * Scoped to the administrator who saved it, so one person's working comparison
 * cannot overwrite another's.
 */
require_once __DIR__ . '/tuning-helpers.php';

pw_require_permission('game_tuning.view');
$db = pw_db();
if (!pw_tuning_scenarios_ready($db)) {
    pw_error('Saved scenarios need sql/migration_game_tuning.sql to have been run.', 503);
}
$user = pw_current_user();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $db->prepare('SELECT id, name, config_json, updated_at FROM game_tuning_scenarios WHERE user_id = ? ORDER BY updated_at DESC, name ASC');
    $stmt->execute([$userId]);
    $rows = array_map(static function ($row) {
        $config = json_decode((string)$row['config_json'], true);
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            // Decoded here so the page never has to parse a stored string. A
            // row that somehow holds invalid JSON degrades to an empty config
            // rather than breaking the whole list.
            'config' => is_array($config) ? $config : [],
            'updated_at' => $row['updated_at'],
        ];
    }, $stmt->fetchAll());
    pw_json(['ok' => true, 'scenarios' => $rows]);
}

$input = pw_input();
pw_require_csrf($input);
$action = (string)($input['action'] ?? 'save');

if ($action === 'delete') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) pw_error('Choose a scenario to delete.');
    // Scoped by user_id as well as id, so a crafted id cannot reach another
    // administrator's saved work.
    $stmt = $db->prepare('DELETE FROM game_tuning_scenarios WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    pw_json(['ok' => true, 'deleted' => $stmt->rowCount() === 1]);
}

$name = trim((string)($input['name'] ?? ''));
if ($name === '' || mb_strlen($name) > 120) pw_error('A scenario name must be between 1 and 120 characters.');
$config = $input['config'] ?? null;
if (!is_array($config)) pw_error('That scenario has no configuration to save.');

/* Re-shaped rather than stored as sent: only the keys this page reads are kept,
 * every id is cast, and the arrays are capped. A saved blob is read back into
 * the simulator, so accepting arbitrary JSON here would be storing unvalidated
 * input for later execution. */
$clean = [
    'crew_definition_id' => max(0, (int)($config['crew_definition_id'] ?? 0)),
    'mission_ids' => array_slice(array_values(array_unique(array_map('intval', is_array($config['mission_ids'] ?? null) ? $config['mission_ids'] : []))), 0, 6),
    'item_ids' => array_slice(array_values(array_unique(array_map('intval', is_array($config['item_ids'] ?? null) ? $config['item_ids'] : []))), 0, 7),
    'research_node_ids' => array_slice(array_values(array_unique(array_map('intval', is_array($config['research_node_ids'] ?? null) ? $config['research_node_ids'] : []))), 0, 200),
    'mode' => in_array((string)($config['mode'] ?? 'level'), ['level', 'crew_count'], true) ? (string)$config['mode'] : 'level',
    'metric' => preg_match('/\A[a-z_]{1,40}\z/', (string)($config['metric'] ?? '')) ? (string)$config['metric'] : 'success_percent',
    'level' => min(PW_MISSION_MAX_LEVEL, max(1, (int)($config['level'] ?? 1))),
    'level_from' => min(PW_MISSION_MAX_LEVEL, max(1, (int)($config['level_from'] ?? 1))),
    'level_to' => min(PW_MISSION_MAX_LEVEL, max(1, (int)($config['level_to'] ?? PW_MISSION_MAX_LEVEL))),
    'crew_count' => min(8, max(1, (int)($config['crew_count'] ?? 1))),
];

$stmt = $db->prepare(
    'INSERT INTO game_tuning_scenarios (user_id, name, config_json) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE config_json = VALUES(config_json)'
);
$stmt->execute([$userId, $name, json_encode($clean)]);
pw_json(['ok' => true, 'name' => $name]);
