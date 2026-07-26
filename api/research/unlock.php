<?php
require_once __DIR__ . '/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$nodeId = filter_var($input['research_node_id'] ?? null, FILTER_VALIDATE_INT);
if ($nodeId === false || $nodeId < 1) pw_error('Choose a valid research protocol.');
$db = pw_db();
pw_research_require_ready($db);
$userId = (int)$user['id'];

try {
    $db->beginTransaction();
    $nodeStmt = $db->prepare(
        'SELECT n.*, COALESCE(category.requires_all_other_unlocked, 0) AS category_requires_all_other_unlocked
         FROM game_research_nodes n
         LEFT JOIN game_research_categories category ON category.id = n.research_category_id
         WHERE n.id = ? FOR UPDATE'
    );
    $nodeStmt->execute([$nodeId]);
    $node = $nodeStmt->fetch();
    if (!$node || !(bool)$node['is_enabled']) throw new RuntimeException('That research protocol is no longer available.');

    $owned = $db->prepare('SELECT 1 FROM game_player_research WHERE user_id = ? AND research_node_id = ? FOR UPDATE');
    $owned->execute([$userId, $nodeId]);
    if ($owned->fetch()) throw new RuntimeException('That research protocol is already unlocked.');

    if (!empty($node['category_requires_all_other_unlocked'])) {
        $remaining = $db->prepare(
            'SELECT COUNT(*) AS total_protocols, SUM(pr.user_id IS NULL) AS remaining_protocols
             FROM game_research_nodes n
             LEFT JOIN game_research_categories category ON category.id = n.research_category_id
             LEFT JOIN game_player_research pr ON pr.research_node_id = n.id AND pr.user_id = ?
             WHERE n.is_enabled = 1
               AND (category.requires_all_other_unlocked = 0 OR category.id IS NULL)
               AND pr.user_id IS NULL'
        );
        $remaining->execute([$userId]);
        $finalGate = $remaining->fetch();
        if ((int)($finalGate['total_protocols'] ?? 0) < 1 || (int)($finalGate['remaining_protocols'] ?? 0) > 0) {
            throw new RuntimeException('The Final Neoh Protocol remains sealed until every other research protocol is online.');
        }
    }

    $rankStmt = $db->prepare('SELECT reputation FROM users WHERE id = ? FOR UPDATE');
    $rankStmt->execute([$userId]);
    $rank = max(0, (int)(pw_reputation_info((int)$rankStmt->fetchColumn())['level_number'] ?? 0));
    if ($rank < (int)$node['required_reputation_level']) {
        throw new RuntimeException('This protocol requires reputation rank ' . (int)$node['required_reputation_level'] . '.');
    }

    $missing = $db->prepare(
        'SELECT n.name
         FROM game_research_prerequisites p
         JOIN game_research_nodes n ON n.id = p.prerequisite_node_id
         LEFT JOIN game_player_research pr ON pr.research_node_id = p.prerequisite_node_id AND pr.user_id = ?
         WHERE p.research_node_id = ? AND pr.user_id IS NULL
         ORDER BY n.sort_order ASC, n.id ASC'
    );
    $missing->execute([$userId, $nodeId]);
    $missingNames = array_column($missing->fetchAll(), 'name');
    if ($missingNames) throw new RuntimeException('Unlock prerequisite research first: ' . implode(', ', $missingNames) . '.');

    $creditCost = (int)$node['credit_cost'];
    if ($creditCost > 0) {
        $db->prepare('INSERT IGNORE INTO game_player_wallet (user_id, credits) VALUES (?, 0)')->execute([$userId]);
        $wallet = $db->prepare('SELECT credits FROM game_player_wallet WHERE user_id = ? FOR UPDATE');
        $wallet->execute([$userId]);
        if ((int)$wallet->fetchColumn() < $creditCost) throw new RuntimeException('You do not have enough credits for this protocol.');
    }

    $salvageId = $node['salvage_loot_definition_id'] !== null ? (int)$node['salvage_loot_definition_id'] : null;
    $salvageQuantity = (int)$node['salvage_quantity'];
    if ($salvageId !== null && $salvageQuantity > 0) {
        $salvage = $db->prepare('SELECT quantity FROM game_player_loot WHERE user_id = ? AND loot_definition_id = ? FOR UPDATE');
        $salvage->execute([$userId, $salvageId]);
        if ((int)$salvage->fetchColumn() < $salvageQuantity) throw new RuntimeException('You do not hold enough of the required salvage item.');
    }

    if ($creditCost > 0) {
        $debit = $db->prepare('UPDATE game_player_wallet SET credits = credits - ? WHERE user_id = ? AND credits >= ?');
        $debit->execute([$creditCost, $userId, $creditCost]);
        if ($debit->rowCount() !== 1) throw new RuntimeException('Your credit balance changed. Refresh and try again.');
    }
    if ($salvageId !== null && $salvageQuantity > 0) {
        $consume = $db->prepare('UPDATE game_player_loot SET quantity = quantity - ? WHERE user_id = ? AND loot_definition_id = ? AND quantity >= ?');
        $consume->execute([$salvageQuantity, $userId, $salvageId, $salvageQuantity]);
        if ($consume->rowCount() !== 1) throw new RuntimeException('Your salvage inventory changed. Refresh and try again.');
    }

    $unlock = $db->prepare('INSERT INTO game_player_research (user_id, research_node_id) VALUES (?, ?)');
    $unlock->execute([$userId, $nodeId]);
    $db->commit();
    pw_json(['ok' => true, 'message' => $node['name'] . ' is now active.']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not unlock that research protocol. Please try again.', 409);
}
