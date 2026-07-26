<?php
require_once __DIR__ . '/missions-helpers.php';
require_once __DIR__ . '/../research/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$offerId = filter_var($input['offer_id'] ?? null, FILTER_VALIDATE_INT);
if ($offerId === false || $offerId < 1) pw_error('Choose a valid held recruit.');
$action = strtolower(trim((string)($input['action'] ?? '')));
if (!in_array($action, ['accept', 'replace', 'sell'], true)) pw_error('Choose how to resolve this recruit.');
$replaceId = $action === 'replace' ? filter_var($input['replace_player_crew_id'] ?? null, FILTER_VALIDATE_INT) : null;
if ($action === 'replace' && ($replaceId === false || $replaceId < 1)) pw_error('Choose an available crew member to replace.');
$db = pw_db();
pw_missions_require_ready($db);
if (!pw_mission_crew_capacity_ready($db)) pw_error('Crew capacity is being prepared. Run sql/migration_crew_capacity.sql first.', 503);
$userId = (int)$user['id'];

try {
    $db->beginTransaction();
    $offerStmt = $db->prepare(
        'SELECT offer.id, offer.crew_definition_id, offer.sale_credits, crew.name, crew.starting_level
         FROM game_player_crew_offers offer
         JOIN game_crew_definitions crew ON crew.id = offer.crew_definition_id AND crew.is_enabled = 1
         WHERE offer.id = ? AND offer.user_id = ? AND offer.status = "pending" FOR UPDATE'
    );
    $offerStmt->execute([$offerId, $userId]);
    $offer = $offerStmt->fetch();
    if (!$offer) throw new RuntimeException('That held recruit is no longer available.');

    $rosterStmt = $db->prepare(
        'SELECT pc.id, pc.crew_definition_id, pc.status, crew.name
         FROM game_player_crew pc
         JOIN game_crew_definitions crew ON crew.id = pc.crew_definition_id AND crew.is_enabled = 1
         WHERE pc.user_id = ? AND pc.status <> "retired" FOR UPDATE'
    );
    $rosterStmt->execute([$userId]);
    $roster = $rosterStmt->fetchAll();
    $capacity = pw_missions_crew_capacity($db, $userId);

    if ($action === 'sell') {
        if (!pw_mission_credits_ready($db)) throw new RuntimeException('Credits are not available yet. Please try again shortly.');
        $creditValue = max(0, (int)$offer['sale_credits']);
        pw_missions_add_credits($db, $userId, $creditValue);
        $resolve = $db->prepare('UPDATE game_player_crew_offers SET status = "sold", resolved_at = UTC_TIMESTAMP() WHERE id = ? AND status = "pending"');
        $resolve->execute([$offerId]);
        if ($resolve->rowCount() !== 1) throw new RuntimeException('That held recruit was already resolved.');
        $db->commit();
        pw_json(['ok' => true, 'message' => $offer['name'] . ' was sold for ' . number_format($creditValue) . ' credits.', 'credits' => pw_missions_credit_balance($db, $userId)]);
    }

    $replacedName = null;
    if ($action === 'replace') {
        $replace = null;
        foreach ($roster as $member) {
            if ((int)$member['id'] === (int)$replaceId) { $replace = $member; break; }
        }
        if (!$replace || $replace['status'] !== 'available') throw new RuntimeException('Only an available crew member can be replaced.');
        $retire = $db->prepare('UPDATE game_player_crew SET status = "retired", is_favorite = 0 WHERE id = ? AND user_id = ? AND status = "available"');
        $retire->execute([(int)$replace['id'], $userId]);
        if ($retire->rowCount() !== 1) throw new RuntimeException('That crew member is no longer available to replace.');
        /* Equipment remains in inventory, but a retired member may not keep a
         * copy reserved and block the new active roster from equipping it. */
        if (pw_mission_gear_ready($db)) {
            $db->prepare('DELETE FROM game_player_crew_gear WHERE user_id = ? AND player_crew_id = ?')->execute([$userId, (int)$replace['id']]);
        }
        $replacedName = (string)$replace['name'];
    } elseif (count($roster) >= $capacity) {
        throw new RuntimeException('You do not have enough crew member space. Unlock more capacity, replace an available crew member, or sell this recruit.');
    }

    $grant = $db->prepare('INSERT IGNORE INTO game_player_crew (user_id, crew_definition_id, level, xp, status) VALUES (?, ?, ?, 0, "available")');
    $grant->execute([$userId, (int)$offer['crew_definition_id'], (int)$offer['starting_level']]);
    if ($grant->rowCount() !== 1) throw new RuntimeException('That recruit is already recorded on your expedition.');
    $resolve = $db->prepare('UPDATE game_player_crew_offers SET status = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ? AND status = "pending"');
    $resolve->execute([$action === 'replace' ? 'replaced' : 'accepted', $offerId]);
    if ($resolve->rowCount() !== 1) throw new RuntimeException('That held recruit was already resolved.');
    $db->commit();
    pw_json(['ok' => true, 'message' => $offer['name'] . ' joined your expedition.' . ($replacedName ? ' ' . $replacedName . ' has been retired from active duty.' : '')]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not resolve that held recruit. Please try again.', 409);
}
