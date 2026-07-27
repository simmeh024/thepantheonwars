<?php
/**
 * Destroy items the player holds -- equipment, stims or salvage.
 *
 * The generalisation of api/missions/gear-destroy.php, which could only ever
 * remove a slotted item and so left salvage and stims permanently stuck once
 * the inventory ceiling was reached. That endpoint now delegates here so there
 * is one implementation of the ownership and equipped-copies rules.
 *
 * Quantity is explicit rather than always one: clearing space under a cap
 * otherwise means a hundred round trips.
 */
require_once __DIR__ . '/missions-helpers.php';
require_once __DIR__ . '/../research/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$itemId = filter_var($input['loot_definition_id'] ?? null, FILTER_VALIDATE_INT);
if ($itemId === false || $itemId < 1) pw_error('Choose a valid item.');
/* "all" is resolved against the spare count inside the transaction rather than
 * by the browser sending a number it read a moment ago, which could have gone
 * stale against a mission that claimed in another tab. */
$destroyAll = ($input['quantity'] ?? null) === 'all';
$quantity = $destroyAll ? 0 : filter_var($input['quantity'] ?? 1, FILTER_VALIDATE_INT);
if (!$destroyAll && ($quantity === false || $quantity < 1 || $quantity > 999)) pw_error('Choose how many to destroy, between 1 and 999.');

$db = pw_db();
pw_missions_require_ready($db);
$userId = (int)$user['id'];
/* Only so the receipt reports the ceilings the player is actually under -- a
 * destroy never checks them. */
$researchEffects = pw_research_player_effects($db, $userId);
$gearReady = pw_mission_gear_ready($db);
$stimsReady = pw_mission_stims_ready($db);

try {
    $db->beginTransaction();

    /* The same ownership row gear-equip.php locks before comparing equipped
     * copies. Serializing both actions on it means a destroy cannot race an
     * equip and leave a crew member wearing more copies than remain owned. */
    $heldStmt = $db->prepare(
        'SELECT pl.quantity, l.name' . ($gearReady ? ', l.slot' : ', "" AS slot')
        . ($stimsReady ? ', l.stim_effect' : ', "" AS stim_effect') . '
         FROM game_player_loot pl
         JOIN game_loot_definitions l ON l.id = pl.loot_definition_id
         WHERE pl.user_id = ? AND pl.loot_definition_id = ?
         FOR UPDATE'
    );
    $heldStmt->execute([$userId, $itemId]);
    $held = $heldStmt->fetch();
    if (!$held || (int)$held['quantity'] < 1) throw new RuntimeException('That item is no longer in your inventory.');

    /* Equipped copies are not deducted from game_player_loot -- that table is a
     * quantity ledger with no per-copy identity -- so the spare count is what
     * may be destroyed. Without this an equipped item could be destroyed out
     * from under the crew member wearing it. */
    $equipped = 0;
    if ($gearReady) {
        $equippedStmt = $db->prepare('SELECT COUNT(*) FROM game_player_crew_gear WHERE user_id = ? AND loot_definition_id = ?');
        $equippedStmt->execute([$userId, $itemId]);
        $equipped = (int)$equippedStmt->fetchColumn();
    }
    $owned = (int)$held['quantity'];
    $spare = max(0, $owned - $equipped);
    if ($spare < 1) {
        throw new RuntimeException('Unequip a copy of ' . $held['name'] . ' before destroying it.');
    }
    $destroy = $destroyAll ? $spare : min($quantity, $spare);
    if ($destroy < 1) throw new RuntimeException('There is nothing spare to destroy.');
    if (!$destroyAll && $quantity > $spare) {
        throw new RuntimeException('You only have ' . $spare . ' spare ' . ($spare === 1 ? 'copy' : 'copies') . ' of ' . $held['name'] . '.');
    }

    $remaining = $owned - $destroy;
    if ($remaining > 0) {
        $update = $db->prepare('UPDATE game_player_loot SET quantity = quantity - ? WHERE user_id = ? AND loot_definition_id = ? AND quantity >= ?');
        $update->execute([$destroy, $userId, $itemId, $destroy]);
        if ($update->rowCount() !== 1) throw new RuntimeException('That item could not be destroyed.');
    } else {
        $delete = $db->prepare('DELETE FROM game_player_loot WHERE user_id = ? AND loot_definition_id = ? AND quantity = ?');
        $delete->execute([$userId, $itemId, $destroy]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('That item could not be destroyed.');
    }

    $usage = pw_missions_inventory_usage($db, $userId, $researchEffects);
    $db->commit();
    pw_json([
        'ok' => true,
        'loot_definition_id' => $itemId,
        'destroyed' => $destroy,
        'remaining_quantity' => $remaining,
        'inventory' => $usage,
        'message' => $destroy === 1
            ? $held['name'] . ' destroyed.'
            : $destroy . ' x ' . $held['name'] . ' destroyed.',
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not destroy that item. Please try again.', 409);
}
