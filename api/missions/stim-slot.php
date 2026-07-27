<?php
/**
 * Assign a stim to a quick slot on the belt, or clear one.
 *
 * Send a loot_definition_id to fill the slot, or null to empty it. Assigning a
 * stim that already occupies another slot moves it rather than duplicating it:
 * quantity is a stack, so two slots holding the same stim would show the same
 * number twice and draw from one pool.
 */
require_once __DIR__ . '/missions-helpers.php';
require_once __DIR__ . '/../research/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$slotIndex = filter_var($input['slot_index'] ?? null, FILTER_VALIDATE_INT);
if ($slotIndex === false || $slotIndex < 0) pw_error('Choose a valid quick slot.');
$raw = $input['loot_definition_id'] ?? null;
$clearing = $raw === null || $raw === '' || $raw === 0 || $raw === '0';
$itemId = $clearing ? null : filter_var($raw, FILTER_VALIDATE_INT);
if (!$clearing && ($itemId === false || $itemId < 1)) pw_error('Choose a valid stim.');

$db = pw_db();
pw_missions_require_ready($db);
if (!pw_mission_stim_slots_ready($db)) pw_error('The stim belt is not available yet.', 409);
$userId = (int)$user['id'];

/* Re-derived from this player's own research rather than trusted from the
 * request. Without it a slot index past the belt could be written by a crafted
 * POST and would then be unreachable in the grid but still hold a stim. */
$researchEffects = pw_research_player_effects($db, $userId);
$capacity = pw_missions_stim_slot_capacity($researchEffects);
if ($slotIndex >= $capacity) {
    pw_error('That quick slot is beyond your belt. You have ' . $capacity . '.', 409);
}

try {
    $db->beginTransaction();

    if ($clearing) {
        $clear = $db->prepare('DELETE FROM game_player_stim_slots WHERE user_id = ? AND slot_index = ?');
        $clear->execute([$userId, $slotIndex]);
        $db->commit();
        pw_json(['ok' => true, 'slot_index' => $slotIndex, 'loot_definition_id' => null, 'message' => 'Quick slot cleared.']);
    }

    /* Ownership is required to assign, not merely to use: a belt full of stims
     * the player has never held would be a planning tool rather than a belt,
     * and every other surface here refuses to reference an item you do not
     * have. Locked for the same reason the other stim paths lock it. */
    $itemStmt = $db->prepare(
        'SELECT l.id, l.name, l.slot, l.stim_effect, l.is_enabled, pl.quantity
         FROM game_loot_definitions l
         JOIN game_player_loot pl ON pl.loot_definition_id = l.id AND pl.user_id = ?
         WHERE l.id = ?
         FOR UPDATE'
    );
    $itemStmt->execute([$userId, $itemId]);
    $item = $itemStmt->fetch();
    if (!$item || (int)$item['quantity'] < 1) throw new RuntimeException('That stim is not in your inventory.');
    if (!(int)$item['is_enabled']) throw new RuntimeException('That stim has been withdrawn from service.');
    // Re-derived from the columns, exactly as api/missions/stim-use.php does, so
    // the browser cannot decide that an ordinary item is a stim.
    if (pw_missions_inventory_category($item) !== 'stim') throw new RuntimeException('Only a stim can go in a quick slot.');

    /* Move rather than duplicate. The unique key on (user_id,
     * loot_definition_id) would reject the insert anyway; clearing the old row
     * first makes it a move, which is what dragging a stim to a new slot means. */
    $release = $db->prepare('DELETE FROM game_player_stim_slots WHERE user_id = ? AND loot_definition_id = ?');
    $release->execute([$userId, $itemId]);

    $assign = $db->prepare(
        'INSERT INTO game_player_stim_slots (user_id, slot_index, loot_definition_id) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE loot_definition_id = VALUES(loot_definition_id), assigned_at = CURRENT_TIMESTAMP'
    );
    $assign->execute([$userId, $slotIndex, $itemId]);

    $db->commit();
    pw_json([
        'ok' => true,
        'slot_index' => $slotIndex,
        'loot_definition_id' => (int)$itemId,
        'message' => $item['name'] . ' assigned to quick slot ' . ($slotIndex + 1) . '.',
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not change that quick slot. Please try again.', 409);
}
