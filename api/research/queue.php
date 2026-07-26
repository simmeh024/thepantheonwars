<?php
require_once __DIR__ . '/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$db = pw_db();
pw_research_require_ready($db);
if (!pw_research_queue_transmissions_ready($db)) {
    pw_error('Research queue support is being prepared. Run sql/migration_research_queue_transmissions.sql first.', 503);
}
$userId = (int)$user['id'];
$rawNodeId = trim((string)($input['research_node_id'] ?? ''));

try {
    if ($rawNodeId === '') {
        $db->prepare('DELETE FROM game_player_research_queue WHERE user_id = ?')->execute([$userId]);
        pw_json(['ok' => true, 'message' => 'Research queue cleared.']);
    }
    $nodeId = filter_var($rawNodeId, FILTER_VALIDATE_INT);
    if ($nodeId === false || $nodeId < 1) throw new RuntimeException('Choose a valid research protocol to queue.');

    $db->beginTransaction();
    $nodeStmt = $db->prepare(
        'SELECT n.id, n.name, n.is_enabled, COALESCE(category.requires_all_other_unlocked, 0) AS category_requires_all_other_unlocked
         FROM game_research_nodes n
         LEFT JOIN game_research_categories category ON category.id = n.research_category_id
         WHERE n.id = ? FOR UPDATE'
    );
    $nodeStmt->execute([$nodeId]);
    $node = $nodeStmt->fetch();
    if (!$node || !(bool)$node['is_enabled']) throw new RuntimeException('That research protocol is no longer available.');

    $owned = $db->prepare('SELECT 1 FROM game_player_research WHERE user_id = ? AND research_node_id = ? FOR UPDATE');
    $owned->execute([$userId, $nodeId]);
    if ($owned->fetch()) throw new RuntimeException('That protocol is already online and cannot be queued.');

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
            throw new RuntimeException('The Final Neoh Protocol cannot be queued until every other research protocol is online.');
        }
    }

    $queue = $db->prepare(
        'INSERT INTO game_player_research_queue (user_id, research_node_id)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE research_node_id = VALUES(research_node_id), queued_at = CURRENT_TIMESTAMP'
    );
    $queue->execute([$userId, $nodeId]);
    $db->commit();
    pw_json(['ok' => true, 'message' => $node['name'] . ' is now your next research protocol.']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not update the research queue. Please try again.', 409);
}
