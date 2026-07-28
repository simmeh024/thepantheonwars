<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
pw_require_permission('missions.view');
$input = pw_input(); pw_require_csrf($input);
$db = pw_db();
if (!pw_mission_contract_progression_ready($db)) {
    pw_error('Run the contract progression migration before using its preview.', 409);
}

/* Existing missions include their linked tables. A new, unsaved definition has
 * no row to link yet, but can still preview the ordinary world pool so authors
 * get useful feedback before their first save. The negative id can never match
 * the unsigned database key, therefore it deliberately has no table links. */
$rawId = trim((string)($input['mission_id'] ?? ''));
$missionId = -1;
$mission = [];
if ($rawId !== '') {
    $missionId = filter_var($rawId, FILTER_VALIDATE_INT);
    if ($missionId === false || $missionId < 1) pw_error('Choose a valid mission for this preview.');
    $stmt = $db->prepare('SELECT id, world_key, loot_rolls FROM game_mission_definitions WHERE id = ?');
    $stmt->execute([$missionId]);
    $mission = $stmt->fetch();
    if (!$mission) pw_error('Mission definition not found.', 404);
}
$worldKey = trim((string)($input['world_key'] ?? ($mission['world_key'] ?? 'neoh')));
if ($worldKey !== 'neoh') pw_error('Neoh is the only playable mission world in V0.');
$lootRolls = filter_var($input['loot_rolls'] ?? ($mission['loot_rolls'] ?? 0), FILTER_VALIDATE_INT);
if ($lootRolls === false || $lootRolls < 0 || $lootRolls > 10) pw_error('Loot rolls must be between 0 and 10.');
$progression = pw_admin_mission_progression_input($input);
$previewMission = array_merge($mission, $progression, [
    'id' => (int)$missionId,
    'world_key' => $worldKey,
    'loot_rolls' => (int)$lootRolls,
]);
$previews = pw_admin_mission_progression_previews($db, [$previewMission]);
pw_json(['ok' => true, 'preview' => $previews[(int)$missionId] ?? []]);
