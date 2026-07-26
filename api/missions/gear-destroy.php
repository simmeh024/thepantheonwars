<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$itemId = filter_var($input['loot_definition_id'] ?? null, FILTER_VALIDATE_INT);
if ($itemId === false || $itemId < 1) pw_error('Choose a valid piece of equipment.');

$db = pw_db();
pw_missions_require_ready($db);
if (!pw_mission_gear_ready($db)) pw_error('Equipment is not available yet.', 409);
$userId = (int)$user['id'];

try {
    $db->beginTransaction();

    /* This is the same ownership row gear-equip.php locks before comparing
     * equipped copies. Serializing both actions on it means a destroy cannot
     * race an equip and leave a crew wearing more copies than remain owned. */
    $heldStmt = $db->prepare(
        'SELECT pl.quantity, l.name, l.slot
         FROM game_player_loot pl
         JOIN game_loot_definitions l ON l.id = pl.loot_definition_id
         WHERE pl.user_id = ? AND pl.loot_definition_id = ?
         FOR UPDATE'
    );
    $heldStmt->execute([$userId, $itemId]);
    $held = $heldStmt->fetch();
    if (!$held || (int)$held['quantity'] < 1) throw new RuntimeException('That equipment is no longer in your inventory.');
    if (!isset(pw_missions_gear_slots()[(string)$held['slot']])) throw new RuntimeException('Only equipment can be destroyed here.');

    $equippedStmt = $db->prepare(
        'SELECT COUNT(*) FROM game_player_crew_gear WHERE user_id = ? AND loot_definition_id = ?'
    );
    $equippedStmt->execute([$userId, $itemId]);
    $equipped = (int)$equippedStmt->fetchColumn();
    $quantity = (int)$held['quantity'];
    if ($quantity <= $equipped) {
        throw new RuntimeException('Unequip a copy of ' . $held['name'] . ' before destroying it.');
    }

    $remaining = $quantity - 1;
    if ($remaining > 0) {
        $update = $db->prepare(
            'UPDATE game_player_loot SET quantity = quantity - 1 WHERE user_id = ? AND loot_definition_id = ? AND quantity > 0'
        );
        $update->execute([$userId, $itemId]);
        if ($update->rowCount() !== 1) throw new RuntimeException('That equipment could not be destroyed.');
    } else {
        $delete = $db->prepare('DELETE FROM game_player_loot WHERE user_id = ? AND loot_definition_id = ? AND quantity = 1');
        $delete->execute([$userId, $itemId]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('That equipment could not be destroyed.');
    }

    $db->commit();
    pw_json([
        'ok' => true,
        'loot_definition_id' => $itemId,
        'remaining_quantity' => $remaining,
        'message' => $held['name'] . ' destroyed.',
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not destroy that equipment. Please try again.', 409);
}
