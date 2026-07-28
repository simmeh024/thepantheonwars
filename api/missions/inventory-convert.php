<?php
/**
 * Convert a queued group of low-value salvage into a small credit payout.
 *
 * The browser only stages a convenience queue. This endpoint re-checks every
 * row under lock, including the category, tier, holdings and payout, so a
 * crafted request cannot sell equipment, stims or rare discoveries.
 */
require_once __DIR__ . '/missions-helpers.php';
require_once __DIR__ . '/../research/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$rawItems = $input['items'] ?? null;
if (!is_array($rawItems) || !$rawItems) pw_error('Queue at least one salvage item.');

$requested = [];
foreach ($rawItems as $row) {
    if (!is_array($row)) pw_error('The conversion queue is invalid.');
    $itemId = filter_var($row['loot_definition_id'] ?? null, FILTER_VALIDATE_INT);
    $quantity = filter_var($row['quantity'] ?? null, FILTER_VALIDATE_INT);
    if ($itemId === false || $itemId < 1 || $quantity === false || $quantity < 1 || $quantity > 999) {
        pw_error('Each queued salvage item must have a quantity between 1 and 999.');
    }
    $requested[(int)$itemId] = ($requested[(int)$itemId] ?? 0) + (int)$quantity;
    if ($requested[(int)$itemId] > 999) pw_error('A queued item cannot exceed 999 units.');
}
if (count($requested) > 24) pw_error('Convert no more than 24 item types at a time.');
ksort($requested, SORT_NUMERIC);

$db = pw_db();
pw_missions_require_ready($db);
if (!pw_mission_inventory_workbench_ready($db)) {
    pw_error('Salvage conversion is being prepared. Please try again after the Inventory Workbench migration has been run.', 503);
}
$userId = (int)$user['id'];
$researchEffects = pw_research_player_effects($db, $userId);
$stimsReady = pw_mission_stims_ready($db);

try {
    $db->beginTransaction();
    $heldStmt = $db->prepare(
        'SELECT pl.quantity, l.name, l.tier, l.slot'
        . ($stimsReady ? ', l.stim_effect' : ', "" AS stim_effect') . '
         FROM game_player_loot pl
         JOIN game_loot_definitions l ON l.id = pl.loot_definition_id
         WHERE pl.user_id = ? AND pl.loot_definition_id = ?
         FOR UPDATE'
    );
    $decrement = $db->prepare('UPDATE game_player_loot SET quantity = quantity - ? WHERE user_id = ? AND loot_definition_id = ? AND quantity >= ?');
    $remove = $db->prepare('DELETE FROM game_player_loot WHERE user_id = ? AND loot_definition_id = ? AND quantity = ?');
    $converted = [];
    $creditsAwarded = 0;
    foreach ($requested as $itemId => $quantity) {
        $heldStmt->execute([$userId, $itemId]);
        $held = $heldStmt->fetch();
        if (!$held || (int)$held['quantity'] < 1) throw new RuntimeException('One queued item is no longer in your inventory.');
        if (pw_missions_inventory_category($held) !== 'salvage') {
            throw new RuntimeException($held['name'] . ' is not salvage and cannot be converted.');
        }
        $perUnit = pw_missions_salvage_conversion_value((string)$held['tier']);
        if ($perUnit < 1) {
            throw new RuntimeException($held['name'] . ' is too valuable to convert. Rare-or-better finds stay out of the salvage queue.');
        }
        $owned = (int)$held['quantity'];
        if ($quantity > $owned) {
            throw new RuntimeException('You only hold ' . $owned . ' x ' . $held['name'] . '.');
        }
        if ($quantity === $owned) {
            $remove->execute([$userId, $itemId, $quantity]);
            if ($remove->rowCount() !== 1) throw new RuntimeException('A queued item changed before it could be converted.');
        } else {
            $decrement->execute([$quantity, $userId, $itemId, $quantity]);
            if ($decrement->rowCount() !== 1) throw new RuntimeException('A queued item changed before it could be converted.');
        }
        $converted[$itemId] = $quantity;
        $creditsAwarded += $quantity * $perUnit;
    }
    if ($creditsAwarded < 1) throw new RuntimeException('The conversion queue has no value.');
    pw_missions_record_loot_history($db, $userId, $converted, 'converted', 'salvage_conversion', null, 'Converted into low-value credits');
    $balance = pw_missions_add_credits($db, $userId, $creditsAwarded);
    $usage = pw_missions_inventory_usage($db, $userId, $researchEffects);
    $db->commit();
    pw_json([
        'ok' => true,
        'converted' => $converted,
        'credits_awarded' => $creditsAwarded,
        'credit_balance' => $balance,
        'inventory' => $usage,
        'message' => 'Converted ' . array_sum($converted) . ' low-value salvage ' . (array_sum($converted) === 1 ? 'item' : 'items') . ' into ' . $creditsAwarded . ' credits.',
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not convert that salvage. Please try again.', 409);
}
