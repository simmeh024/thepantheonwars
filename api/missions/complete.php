<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$missionId = filter_var($input['mission_id'] ?? null, FILTER_VALIDATE_INT);
if ($missionId === false || $missionId < 1) pw_error('Choose a valid mission.');
$db = pw_db();
pw_missions_require_ready($db);

try {
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT id, status, completes_at FROM game_player_missions WHERE id = ? AND user_id = ? FOR UPDATE');
    $stmt->execute([$missionId, (int)$user['id']]);
    $mission = $stmt->fetch();
    if (!$mission) throw new RuntimeException('Mission not found.');
    if ($mission['status'] === 'claimed') throw new RuntimeException('This mission has already been claimed.');
    if ($mission['status'] === 'completed') {
        $db->commit();
        pw_json(['ok' => true, 'status' => 'completed']);
    }
    $now = pw_missions_utc_now($db);
    if (pw_missions_datetime($now) < $mission['completes_at']) {
        throw new RuntimeException('This mission is still in progress.');
    }
    $update = $db->prepare('UPDATE game_player_missions SET status = "completed", completed_at = ? WHERE id = ? AND status = "active"');
    $update->execute([pw_missions_datetime($now), $missionId]);
    $settled = $update->rowCount() === 1;
    $db->commit();
    /* Notified after the commit and on the transition only, exactly as the cron
     * sweep does. The tab and the sweep must behave identically or whether a
     * player was watching would decide whether the run is recorded in their
     * notifications. */
    if ($settled) {
        $nameStmt = $db->prepare(
            'SELECT md.name FROM game_player_missions pm
             JOIN game_mission_definitions md ON md.id = pm.mission_definition_id
             WHERE pm.id = ?'
        );
        $nameStmt->execute([$missionId]);
        pw_missions_notify_run_ready((int)$user['id'], (string)$nameStmt->fetchColumn());
    }
    pw_json(['ok' => true, 'status' => 'completed']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not complete this mission. Please try again.', 409);
}
