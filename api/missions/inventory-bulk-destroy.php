<?php
/**
 * Destroy several player-selected inventory rows in one transaction.
 *
 * The list view only offers equipment and salvage. This endpoint enforces the
 * same rule independently, and also owns the conservative Level 1-2 gear
 * clean-up action so a crafted browser request can never include stims or an
 * equipped copy.
 */
require_once __DIR__ . '/missions-helpers.php';
require_once __DIR__ . '/../research/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$mode = trim((string)($input['mode'] ?? 'selected'));
if (!in_array($mode, ['selected', 'low_level_gear'], true)) pw_error('Choose a valid bulk inventory action.');

$requested = [];
if ($mode === 'selected') {
    $rawItems = $input['items'] ?? null;
    if (!is_array($rawItems) || !$rawItems) pw_error('Select at least one equipment or salvage item.');
    foreach ($rawItems as $row) {
        if (!is_array($row)) pw_error('The bulk selection is invalid.');
        $itemId = filter_var($row['loot_definition_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($row['quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($itemId === false || $itemId < 1 || $quantity === false || $quantity < 1 || $quantity > 999) {
            pw_error('Each selected item must have a quantity between 1 and 999.');
        }
        $requested[(int)$itemId] = ($requested[(int)$itemId] ?? 0) + (int)$quantity;
        if ($requested[(int)$itemId] > 999) pw_error('A selected item cannot exceed 999 units.');
    }
    /* Inventory limits keep this bounded in practice, but the list view's
     * Select equipment / Select salvage controls should never fail merely
     * because a long-running player owns more than a couple of distinct rows. */
    if (count($requested) > 200) pw_error('Destroy no more than 200 item types at a time.');
    ksort($requested, SORT_NUMERIC);
}

$db = pw_db();
pw_missions_require_ready($db);
$gearReady = pw_mission_gear_ready($db);
if (!$gearReady) pw_error('Inventory bulk management is not available yet.', 409);
$stimsReady = pw_mission_stims_ready($db);
$userId = (int)$user['id'];
$researchEffects = pw_research_player_effects($db, $userId);

try {
    $db->beginTransaction();
    $targets = [];
    $skippedEquipped = 0;
    if ($mode === 'low_level_gear') {
        $lowGearStmt = $db->prepare(
            'SELECT pl.loot_definition_id, pl.quantity, l.name, l.slot, l.required_level
             FROM game_player_loot pl
             JOIN game_loot_definitions l ON l.id = pl.loot_definition_id
             WHERE pl.user_id = ? AND pl.quantity > 0 AND l.slot <> "" AND l.required_level <= ?
             ORDER BY pl.loot_definition_id ASC
             FOR UPDATE'
        );
        $lowGearStmt->execute([$userId, PW_MISSION_BULK_LOW_GEAR_MAX_LEVEL]);
        $equippedStmt = $db->prepare('SELECT COUNT(*) FROM game_player_crew_gear WHERE user_id = ? AND loot_definition_id = ?');
        foreach ($lowGearStmt->fetchAll() as $held) {
            $itemId = (int)$held['loot_definition_id'];
            $equippedStmt->execute([$userId, $itemId]);
            $spare = max(0, (int)$held['quantity'] - (int)$equippedStmt->fetchColumn());
            $skippedEquipped += (int)$held['quantity'] - $spare;
            if ($spare > 0) $targets[$itemId] = ['name' => (string)$held['name'], 'quantity' => $spare, 'owned' => (int)$held['quantity']];
        }
    } else {
        $heldStmt = $db->prepare(
            'SELECT pl.quantity, l.name'
            . ($gearReady ? ', l.slot' : ', "" AS slot')
            . ($stimsReady ? ', l.stim_effect' : ', "" AS stim_effect') . '
             FROM game_player_loot pl
             JOIN game_loot_definitions l ON l.id = pl.loot_definition_id
             WHERE pl.user_id = ? AND pl.loot_definition_id = ?
             FOR UPDATE'
        );
        $equippedStmt = $gearReady
            ? $db->prepare('SELECT COUNT(*) FROM game_player_crew_gear WHERE user_id = ? AND loot_definition_id = ?')
            : null;
        foreach ($requested as $itemId => $quantity) {
            $heldStmt->execute([$userId, $itemId]);
            $held = $heldStmt->fetch();
            if (!$held || (int)$held['quantity'] < 1) throw new RuntimeException('One selected item is no longer in your inventory.');
            $category = pw_missions_inventory_category($held);
            if (!in_array($category, ['gear', 'salvage'], true)) {
                throw new RuntimeException($held['name'] . ' cannot be bulk destroyed. Use it from the field kit instead.');
            }
            $available = (int)$held['quantity'];
            if ($category === 'gear' && $equippedStmt) {
                $equippedStmt->execute([$userId, $itemId]);
                $available = max(0, $available - (int)$equippedStmt->fetchColumn());
            }
            if ($quantity > $available) {
                throw new RuntimeException('Only ' . $available . ' spare ' . ($available === 1 ? 'copy' : 'copies') . ' of ' . $held['name'] . ' can be destroyed.');
            }
            $targets[$itemId] = ['name' => (string)$held['name'], 'quantity' => $quantity, 'owned' => (int)$held['quantity']];
        }
    }
    if (!$targets) throw new RuntimeException('No spare Level 1-2 gear is available to destroy. Equipped copies are always kept.');

    $decrement = $db->prepare('UPDATE game_player_loot SET quantity = quantity - ? WHERE user_id = ? AND loot_definition_id = ? AND quantity >= ?');
    $remove = $db->prepare('DELETE FROM game_player_loot WHERE user_id = ? AND loot_definition_id = ? AND quantity = ?');
    $destroyed = [];
    foreach ($targets as $itemId => $target) {
        $quantity = (int)$target['quantity'];
        $owned = (int)$target['owned'];
        if ($quantity === $owned) {
            $remove->execute([$userId, $itemId, $quantity]);
            if ($remove->rowCount() !== 1) throw new RuntimeException('A selected item changed before it could be destroyed.');
        } else {
            $decrement->execute([$quantity, $userId, $itemId, $quantity]);
            if ($decrement->rowCount() !== 1) throw new RuntimeException('A selected item changed before it could be destroyed.');
        }
        $destroyed[$itemId] = $quantity;
    }
    $note = $mode === 'low_level_gear'
        ? 'Bulk cleared Level 1-' . PW_MISSION_BULK_LOW_GEAR_MAX_LEVEL . ' gear'
        : 'Bulk destroyed from inventory list';
    pw_missions_record_loot_history($db, $userId, $destroyed, 'destroyed', 'quartermaster', null, $note);
    $usage = pw_missions_inventory_usage($db, $userId, $researchEffects);
    $total = array_sum($destroyed);
    $db->commit();
    pw_json([
        'ok' => true,
        'destroyed' => $destroyed,
        'destroyed_quantity' => $total,
        'destroyed_types' => count($destroyed),
        'equipped_copies_kept' => $skippedEquipped,
        'inventory' => $usage,
        'message' => 'Destroyed ' . $total . ' ' . ($total === 1 ? 'item' : 'items') . ' from ' . count($destroyed) . ' ' . (count($destroyed) === 1 ? 'type' : 'types') . '.',
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not destroy the selected inventory. Please try again.', 409);
}
