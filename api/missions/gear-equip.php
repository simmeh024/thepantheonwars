<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$crewId = filter_var($input['crew_id'] ?? null, FILTER_VALIDATE_INT);
$itemId = filter_var($input['loot_definition_id'] ?? null, FILTER_VALIDATE_INT);
if ($crewId === false || $crewId < 1) pw_error('Choose a valid crew member.');
if ($itemId === false || $itemId < 1) pw_error('Choose a valid piece of equipment.');
$db = pw_db();
pw_missions_require_ready($db);
if (!pw_mission_gear_ready($db)) pw_error('Equipment is not available yet.', 409);
$userId = (int)$user['id'];

try {
    $db->beginTransaction();

    /* Locked for the length of the transaction: the ownership check below counts
     * how many copies are already equipped, and two equips racing on the same
     * item could otherwise both pass a check that only one of them should. */
    $crewStmt = $db->prepare(
        'SELECT pc.id, pc.status, pc.level, c.role, c.name
         FROM game_player_crew pc
         JOIN game_crew_definitions c ON c.id = pc.crew_definition_id AND c.is_enabled = 1
         WHERE pc.id = ? AND pc.user_id = ? FOR UPDATE'
    );
    $crewStmt->execute([$crewId, $userId]);
    $crew = $crewStmt->fetch();
    if (!$crew) throw new RuntimeException('That crew member is not on your roster.');
    /* Gear is fixed while a crew member is in the field. The claim recomputes
     * every reward from the crew's stats as they stand when it runs, so allowing
     * a swap mid-operation would let a player launch on one loadout and be paid
     * on another -- the same reason the weather is snapshotted at launch. */
    if ($crew['status'] !== 'available') {
        throw new RuntimeException('Bring this crew member home before changing their loadout.');
    }

    $itemStmt = $db->prepare(
        'SELECT id, name, slot, tier, bonus_strength, bonus_cunning, bonus_science, bonus_charisma,
                required_level, required_role, is_enabled
         FROM game_loot_definitions WHERE id = ?'
    );
    $itemStmt->execute([$itemId]);
    $item = $itemStmt->fetch();
    if (!$item || !(int)$item['is_enabled']) throw new RuntimeException('That equipment is no longer available.');
    $slot = (string)$item['slot'];
    if (!isset(pw_missions_gear_slots()[$slot])) throw new RuntimeException('That item cannot be equipped.');

    $requirement = pw_missions_gear_requirement_error($item, $crew);
    if ($requirement !== '') throw new RuntimeException($requirement);

    // Held quantity, locked before it is compared with what is already equipped.
    $ownedStmt = $db->prepare('SELECT quantity FROM game_player_loot WHERE user_id = ? AND loot_definition_id = ? FOR UPDATE');
    $ownedStmt->execute([$userId, $itemId]);
    $owned = (int)($ownedStmt->fetchColumn() ?: 0);
    if ($owned < 1) throw new RuntimeException('You do not own that equipment.');

    /* The invariant this table exists to protect: copies equipped across the
     * whole roster may never exceed copies owned. The crew member being equipped
     * is excluded from the count, since whatever is in this slot right now is
     * about to be replaced and frees its own copy.
     *
     * Nothing is deducted from game_player_loot -- that is a quantity ledger with
     * no per-copy identity, so spending a unit here would make "how many do I
     * own" ambiguous the moment items can also be sold or consumed. */
    $equippedStmt = $db->prepare(
        'SELECT COUNT(*) FROM game_player_crew_gear
         WHERE user_id = ? AND loot_definition_id = ? AND player_crew_id <> ?'
    );
    $equippedStmt->execute([$userId, $itemId, $crewId]);
    $equippedElsewhere = (int)$equippedStmt->fetchColumn();
    if ($equippedElsewhere >= $owned) {
        throw new RuntimeException($owned === 1
            ? 'Your only copy of that equipment is already carried by another crew member.'
            : 'All ' . $owned . ' copies of that equipment are already carried by your crew.');
    }

    $upsert = $db->prepare(
        'INSERT INTO game_player_crew_gear (user_id, player_crew_id, slot, loot_definition_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE loot_definition_id = VALUES(loot_definition_id), equipped_at = CURRENT_TIMESTAMP'
    );
    $upsert->execute([$userId, $crewId, $slot, $itemId]);

    $db->commit();
    pw_json([
        'ok' => true,
        'crew_id' => $crewId,
        'slot' => $slot,
        'loot_definition_id' => $itemId,
        'message' => $item['name'] . ' equipped to ' . $crew['name'] . '.',
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not change that loadout. Please try again.', 409);
}
