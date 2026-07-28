<?php
/* Resolve one quadrant of the blocked-tile clearance for today's Overlord contract. */
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$missionId = filter_var($input['mission_id'] ?? null, FILTER_VALIDATE_INT);
$cell = filter_var($input['cell'] ?? null, FILTER_VALIDATE_INT);
if ($missionId === false || $missionId < 1 || $cell === false || $cell < 0 || $cell > 3) {
    pw_error('Choose a valid clearance quadrant.');
}
$db = pw_db();
if (!pw_mission_overlord_clearances_ready($db)) {
    pw_error('Run sql/migration_salvage_recovery_contracts.sql before clearing Overlord access tiles.', 409);
}
$userId = (int)$user['id'];

try {
    $db->beginTransaction();
    $missionStmt = $db->prepare('SELECT * FROM game_mission_definitions WHERE id = ? FOR UPDATE');
    $missionStmt->execute([$missionId]);
    $mission = $missionStmt->fetch();
    if (!$mission || !(bool)$mission['is_enabled']) throw new RuntimeException('That contract is no longer available.');
    if ($mission['overlord_id'] === null || (int)$mission['overlord_id'] < 1) {
        throw new RuntimeException('Only an issued Overlord contract has a blocked access tile.');
    }
    $rank = (int)(pw_reputation_info((int)($user['reputation'] ?? 0))['level_number'] ?? 0);
    $overlord = pw_missions_overlord_affinity($db, $user['overlord_affinity'] ?? null);
    $block = pw_missions_overlord_contract_daily_block($db, $userId, $mission, $rank, $overlord);
    if ($block !== null) throw new RuntimeException($block);

    $existing = $db->prepare(
        'SELECT * FROM game_player_overlord_contract_clearances
         WHERE user_id = ? AND mission_definition_id = ? AND issued_date = UTC_DATE() FOR UPDATE'
    );
    $existing->execute([$userId, $missionId]);
    $clearance = $existing->fetch();
    if (!$clearance) {
        /* The chosen collapse index remains private in this row until it is hit.
         * INSERT IGNORE plus the immediate locked re-read handles two quick taps
         * before either request can see a row. */
        $insert = $db->prepare(
            'INSERT IGNORE INTO game_player_overlord_contract_clearances
             (user_id, mission_definition_id, issued_date, collapse_index, safe_picks, status)
             VALUES (?, ?, UTC_DATE(), ?, "", "blocked")'
        );
        $insert->execute([$userId, $missionId, random_int(0, 3)]);
        $existing->execute([$userId, $missionId]);
        $clearance = $existing->fetch();
    }
    if (!$clearance) throw new RuntimeException('Could not prepare the access tile. Please try again.');
    if ($clearance['status'] === 'cleared') throw new RuntimeException('This access tile is already clear.');
    if ($clearance['status'] === 'collapsed') throw new RuntimeException('The access tile has already collapsed for today.');

    $safePicks = pw_missions_overlord_clearance_picks($clearance['safe_picks'] ?? '');
    if (in_array((int)$cell, $safePicks, true)) throw new RuntimeException('That quadrant is already secure.');
    $collapsed = (int)$cell === (int)$clearance['collapse_index'];
    if ($collapsed) {
        $update = $db->prepare('UPDATE game_player_overlord_contract_clearances SET status = "collapsed" WHERE id = ? AND status = "blocked"');
        $update->execute([(int)$clearance['id']]);
        $clearance['status'] = 'collapsed';
    } else {
        $safePicks[] = (int)$cell;
        sort($safePicks, SORT_NUMERIC);
        $clearance['safe_picks'] = implode(',', $safePicks);
        $clearance['status'] = count($safePicks) >= 2 ? 'cleared' : 'blocked';
        $update = $db->prepare('UPDATE game_player_overlord_contract_clearances SET safe_picks = ?, status = ? WHERE id = ? AND status = "blocked"');
        $update->execute([$clearance['safe_picks'], $clearance['status'], (int)$clearance['id']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('The access tile changed before this scan completed.');
    }

    $db->commit();
    $public = pw_missions_overlord_clearance_public($clearance, true);
    pw_json([
        'ok' => true,
        'clearance' => $public,
        'message' => $collapsed
            ? 'The route collapsed. This contract is unavailable until the next UTC reset.'
            : ($public['status'] === 'cleared' ? 'Access tile cleared. The Overlord contract is ready to accept.' : 'Stable quadrant confirmed. Secure one more quadrant.'),
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not clear the access tile. Please try again.', 409);
}
