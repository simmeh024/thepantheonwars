<?php
require_once __DIR__ . '/market-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$itemId = filter_var($input['rotation_item_id'] ?? null, FILTER_VALIDATE_INT);
if ($itemId === false || $itemId < 1) pw_error('Choose a valid market offer.');
$db = pw_db();
pw_market_require_ready($db);
$userId = (int)$user['id'];

try {
    $db->beginTransaction();
    $now = pw_missions_utc_now($db);
    $offerStmt = $db->prepare(
        'SELECT i.id, i.market_rotation_id, i.market_entry_id, i.credit_price, i.required_reputation_level, i.stock_remaining,
                r.offer_type, r.window_started_at, r.window_ends_at,
                e.loot_definition_id, e.crew_definition_id, e.is_enabled AS entry_enabled
         FROM game_market_rotation_items i
         JOIN game_market_rotations r ON r.id = i.market_rotation_id
         JOIN game_market_entries e ON e.id = i.market_entry_id
         WHERE i.id = ? FOR UPDATE'
    );
    $offerStmt->execute([$itemId]);
    $offer = $offerStmt->fetch();
    if (!$offer || !(int)$offer['entry_enabled']) throw new RuntimeException('That market offer is no longer available.');
    [$expectedStart] = pw_market_window($now, (string)$offer['offer_type']);
    if ($offer['window_started_at'] !== pw_missions_datetime($expectedStart) || $offer['window_ends_at'] <= pw_missions_datetime($now)) {
        throw new RuntimeException('That market rotation has ended. Refresh to see the new offers.');
    }
    if ((int)$offer['stock_remaining'] < 1) throw new RuntimeException('That offer has sold out.');

    $reputationStmt = $db->prepare('SELECT reputation FROM users WHERE id = ? FOR UPDATE');
    $reputationStmt->execute([$userId]);
    if (pw_market_reputation_level((int)$reputationStmt->fetchColumn()) < (int)$offer['required_reputation_level']) {
        throw new RuntimeException('Your current reputation rank has not unlocked that offer.');
    }

    $name = '';
    if ($offer['offer_type'] === 'gear') {
        $sourceStmt = $db->prepare('SELECT id, name FROM game_loot_definitions WHERE id = ? AND is_enabled = 1 AND slot <> "" FOR UPDATE');
        $sourceStmt->execute([(int)$offer['loot_definition_id']]);
        $source = $sourceStmt->fetch();
        if (!$source) throw new RuntimeException('That equipment is no longer available.');
        $name = (string)$source['name'];
    } elseif ($offer['offer_type'] === 'character') {
        $sourceStmt = $db->prepare('SELECT id, name FROM game_crew_definitions WHERE id = ? AND is_enabled = 1 AND is_starter = 0 FOR UPDATE');
        $sourceStmt->execute([(int)$offer['crew_definition_id']]);
        $source = $sourceStmt->fetch();
        if (!$source) throw new RuntimeException('That character is no longer available.');
        $owned = $db->prepare('SELECT id FROM game_player_crew WHERE user_id = ? AND crew_definition_id = ? FOR UPDATE');
        $owned->execute([$userId, (int)$offer['crew_definition_id']]);
        if ($owned->fetch()) throw new RuntimeException('That character is already part of your expedition force.');
        $name = (string)$source['name'];
    } else {
        throw new RuntimeException('That market offer is invalid.');
    }

    $db->prepare('INSERT IGNORE INTO game_player_wallet (user_id, credits) VALUES (?, 0)')->execute([$userId]);
    $wallet = $db->prepare('SELECT credits FROM game_player_wallet WHERE user_id = ? FOR UPDATE');
    $wallet->execute([$userId]);
    if ((int)$wallet->fetchColumn() < (int)$offer['credit_price']) throw new RuntimeException('You do not have enough credits for that offer.');

    $stock = $db->prepare('UPDATE game_market_rotation_items SET stock_remaining = stock_remaining - 1 WHERE id = ? AND stock_remaining > 0');
    $stock->execute([$itemId]);
    if ($stock->rowCount() !== 1) throw new RuntimeException('That offer has sold out.');
    $debit = $db->prepare('UPDATE game_player_wallet SET credits = credits - ? WHERE user_id = ? AND credits >= ?');
    $debit->execute([(int)$offer['credit_price'], $userId, (int)$offer['credit_price']]);
    if ($debit->rowCount() !== 1) throw new RuntimeException('Your credit balance changed. Refresh and try again.');

    if ($offer['offer_type'] === 'gear') {
        $grant = $db->prepare('INSERT INTO game_player_loot (user_id, loot_definition_id, quantity) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE quantity = quantity + 1');
        $grant->execute([$userId, (int)$offer['loot_definition_id']]);
    } else {
        $grant = $db->prepare('INSERT INTO game_player_crew (user_id, crew_definition_id, level, xp, status) SELECT ?, id, starting_level, 0, "available" FROM game_crew_definitions WHERE id = ?');
        $grant->execute([$userId, (int)$offer['crew_definition_id']]);
        if ($grant->rowCount() !== 1) throw new RuntimeException('That character could not be recruited.');
    }
    $purchase = $db->prepare('INSERT INTO game_market_purchases (user_id, rotation_item_id, offer_type, loot_definition_id, crew_definition_id, item_name, credit_price) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $purchase->execute([$userId, $itemId, $offer['offer_type'], $offer['loot_definition_id'], $offer['crew_definition_id'], $name, (int)$offer['credit_price']]);
    $balance = pw_missions_credit_balance($db, $userId);
    $db->commit();
    pw_json(['ok' => true, 'message' => $name . ' added to your expedition.', 'credits' => $balance]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'The purchase could not be completed. Please try again.', 409);
}
