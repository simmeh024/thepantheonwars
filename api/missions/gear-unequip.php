<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$crewId = filter_var($input['crew_id'] ?? null, FILTER_VALIDATE_INT);
$slot = strtolower(trim((string)($input['slot'] ?? '')));
if ($crewId === false || $crewId < 1) pw_error('Choose a valid crew member.');
if (!isset(pw_missions_gear_slots()[$slot])) pw_error('Choose a valid equipment slot.');
$db = pw_db();
pw_missions_require_ready($db);
if (!pw_mission_gear_ready($db)) pw_error('Equipment is not available yet.', 409);
$userId = (int)$user['id'];

/* Same deployed-crew rule as equipping, and for the same reason: the claim
 * recomputes rewards from the crew's stats when it runs, so stripping gear from
 * a crew already in the field would pay out against a loadout the operation was
 * never launched on. */
$crewStmt = $db->prepare('SELECT status FROM game_player_crew WHERE id = ? AND user_id = ?');
$crewStmt->execute([$crewId, $userId]);
$status = $crewStmt->fetchColumn();
if ($status === false) pw_error('That crew member is not on your roster.', 409);
if ($status !== 'available') pw_error('Bring this crew member home before changing their loadout.', 409);

/* Nothing to return to the inventory: equipping never removed the copy from
 * game_player_loot, it only counted against it. Deleting the row is the whole
 * of unequipping. */
$delete = $db->prepare('DELETE FROM game_player_crew_gear WHERE user_id = ? AND player_crew_id = ? AND slot = ?');
$delete->execute([$userId, $crewId, $slot]);

pw_json(['ok' => true, 'crew_id' => $crewId, 'slot' => $slot, 'removed' => $delete->rowCount() > 0]);
