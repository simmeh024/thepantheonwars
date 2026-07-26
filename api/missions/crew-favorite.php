<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$crewId = filter_var($input['crew_id'] ?? null, FILTER_VALIDATE_INT);
if (!array_key_exists('is_favorite', $input)) pw_error('Choose whether to favourite this crew member.');
$favorite = filter_var($input['is_favorite'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($crewId === false || $crewId < 1) pw_error('Choose a valid crew member.');
if ($favorite === null) pw_error('Choose whether to favourite this crew member.');

$db = pw_db();
pw_missions_require_ready($db);
if (!pw_mission_crew_favorites_ready($db)) {
    pw_error('Crew favourites are being prepared. Please try again after the favourites migration has run.', 503);
}
$userId = (int)$user['id'];

try {
    $update = $db->prepare('UPDATE game_player_crew SET is_favorite = ? WHERE id = ? AND user_id = ?');
    $update->execute([$favorite ? 1 : 0, $crewId, $userId]);

    /* An idempotent update legitimately affects zero rows when the button was
     * double-clicked or another session already made the same choice, so read
     * the row back instead of treating rowCount() as an ownership check. */
    $read = $db->prepare('SELECT is_favorite FROM game_player_crew WHERE id = ? AND user_id = ?');
    $read->execute([$crewId, $userId]);
    $stored = $read->fetchColumn();
    if ($stored === false) throw new RuntimeException('That crew member is not on your roster.');

    pw_json(['ok' => true, 'crew_id' => $crewId, 'is_favorite' => (bool)$stored]);
} catch (Throwable $e) {
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not update that crew favourite.', 409);
}
