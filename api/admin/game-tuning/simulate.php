<?php
/**
 * Runs one comparison and returns a series per mission.
 *
 * Read-only in the strictest sense: it opens no transaction, writes nothing,
 * and touches no game_player_* table. Every number it returns came out of the
 * live mission helpers -- see tuning-helpers.php for why that matters.
 *
 * A POST rather than a GET because the input is a structured bundle (a
 * loadout, a research set, a mission list) that does not belong in a query
 * string, and because CSRF protection comes free with the existing helper.
 */
require_once __DIR__ . '/tuning-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
pw_require_permission('game_tuning.view');
$input = pw_input();
pw_require_csrf($input);
$db = pw_db();
if (!pw_tuning_ready($db)) {
    pw_error('Game Tuning needs the Missions and crew-stats migrations before it can simulate anything.', 503);
}

$crewId = filter_var($input['crew_definition_id'] ?? null, FILTER_VALIDATE_INT);
if ($crewId === false || $crewId < 1) pw_error('Choose a crew member to simulate.');
$crewStmt = $db->prepare('SELECT id, name, role, starting_level FROM game_crew_definitions WHERE id = ?');
$crewStmt->execute([$crewId]);
$definition = $crewStmt->fetch();
if (!$definition) pw_error('That crew member no longer exists.', 404);

$missionIds = array_values(array_unique(array_filter(
    array_map('intval', is_array($input['mission_ids'] ?? null) ? $input['mission_ids'] : []),
    static function ($id) { return $id > 0; }
)));
if (!$missionIds) pw_error('Choose at least one operation to simulate against.');
if (count($missionIds) > 6) pw_error('Compare at most six operations at once.');
$creditsReady = pw_mission_credits_ready($db);
$missionStmt = $db->prepare(
    'SELECT id, name, mission_type, duration_seconds, min_crew, max_crew, xp_reward,
            reputation_reward, base_success_percent, loot_rolls'
    . ($creditsReady ? ', credit_reward' : ', 0 AS credit_reward') . '
     FROM game_mission_definitions WHERE id IN (' . pw_missions_placeholders(count($missionIds)) . ')
     ORDER BY sort_order ASC, id ASC'
);
$missionStmt->execute($missionIds);
$missions = $missionStmt->fetchAll();
if (!$missions) pw_error('None of those operations exist any more.', 404);

/* Item and node ids are re-read from the catalogue rather than trusted, so a
 * saved scenario naming something since deleted simply loses that entry. */
$loadout = pw_tuning_loadout($db, is_array($input['item_ids'] ?? null) ? $input['item_ids'] : []);
$research = pw_tuning_research_effects($db, is_array($input['research_node_ids'] ?? null) ? $input['research_node_ids'] : []);

$mode = (string)($input['mode'] ?? 'level');
if (!in_array($mode, ['level', 'crew_count'], true)) pw_error('Choose a level or crew-count sweep.');

$fixedLevel = filter_var($input['level'] ?? 1, FILTER_VALIDATE_INT);
if ($fixedLevel === false || $fixedLevel < 1 || $fixedLevel > PW_MISSION_MAX_LEVEL) $fixedLevel = 1;
$fixedCount = filter_var($input['crew_count'] ?? 1, FILTER_VALIDATE_INT);
if ($fixedCount === false || $fixedCount < 1 || $fixedCount > 8) $fixedCount = 1;

$levelFrom = filter_var($input['level_from'] ?? 1, FILTER_VALIDATE_INT);
$levelTo = filter_var($input['level_to'] ?? PW_MISSION_MAX_LEVEL, FILTER_VALIDATE_INT);
if ($levelFrom === false || $levelFrom < 1) $levelFrom = 1;
if ($levelTo === false || $levelTo > PW_MISSION_MAX_LEVEL) $levelTo = PW_MISSION_MAX_LEVEL;
if ($levelTo < $levelFrom) $levelTo = $levelFrom;

$series = [];
foreach ($missions as $mission) {
    $points = [];
    if ($mode === 'crew_count') {
        /* Swept across what this operation actually accepts, not 1..8: a point
         * outside min_crew..max_crew is a team the game would refuse to launch,
         * and plotting it would invite tuning against an impossible case. */
        for ($count = (int)$mission['min_crew']; $count <= (int)$mission['max_crew']; $count++) {
            $points[] = pw_tuning_simulate_point($db, $definition, $mission, $fixedLevel, $count, $loadout, $research);
        }
    } else {
        /* Sampled at a stride when the span is longer than the chart can carry
         * a readable point for, always including both ends so the curve keeps
         * its real start and finish. */
        $span = $levelTo - $levelFrom + 1;
        $stride = (int)max(1, ceil($span / PW_TUNING_MAX_POINTS));
        $count = max((int)$mission['min_crew'], min($fixedCount, (int)$mission['max_crew']));
        for ($level = $levelFrom; $level <= $levelTo; $level += $stride) {
            $points[] = pw_tuning_simulate_point($db, $definition, $mission, $level, $count, $loadout, $research);
        }
        if ($points && (int)$points[count($points) - 1]['level'] !== $levelTo) {
            $points[] = pw_tuning_simulate_point($db, $definition, $mission, $levelTo, $count, $loadout, $research);
        }
    }
    $series[] = [
        'mission_id' => (int)$mission['id'],
        'mission_name' => (string)$mission['name'],
        'mission_type' => (string)$mission['mission_type'],
        'min_crew' => (int)$mission['min_crew'],
        'max_crew' => (int)$mission['max_crew'],
        'points' => $points,
    ];
}

pw_json([
    'ok' => true,
    'mode' => $mode,
    'crew' => ['id' => (int)$definition['id'], 'name' => (string)$definition['name'], 'role' => (string)$definition['role']],
    'loadout' => array_values($loadout),
    'research_effects' => $research,
    'series' => $series,
    'metrics' => pw_tuning_metrics(),
]);
