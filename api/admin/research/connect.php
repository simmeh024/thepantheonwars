<?php
require_once __DIR__ . '/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('research.manage');
$input = pw_input();
pw_require_csrf($input);

$prerequisiteId = filter_var($input['prerequisite_node_id'] ?? null, FILTER_VALIDATE_INT);
$researchNodeId = filter_var($input['research_node_id'] ?? null, FILTER_VALIDATE_INT);
if ($prerequisiteId === false || $researchNodeId === false || $prerequisiteId < 1 || $researchNodeId < 1 || $prerequisiteId === $researchNodeId) {
    pw_error('Choose two different research protocols to connect.');
}

$db = pw_db();
pw_admin_research_require_ready($db);

try {
    $db->beginTransaction();
    $ids = [$prerequisiteId, $researchNodeId];
    sort($ids, SORT_NUMERIC);
    $locked = $db->prepare('SELECT id, name FROM game_research_nodes WHERE id IN (?, ?) FOR UPDATE');
    $locked->execute($ids);
    $nodeRows = $locked->fetchAll();
    if (count($nodeRows) !== 2) throw new RuntimeException('One of those research protocols no longer exists.');

    $current = $db->prepare('SELECT prerequisite_node_id FROM game_research_prerequisites WHERE research_node_id = ? FOR UPDATE');
    $current->execute([$researchNodeId]);
    $prerequisites = array_map('intval', $current->fetchAll(PDO::FETCH_COLUMN));
    $alreadyLinked = in_array($prerequisiteId, $prerequisites, true);
    if (!$alreadyLinked) {
        $prerequisites[] = $prerequisiteId;
        pw_admin_research_prerequisites($db, $researchNodeId, $prerequisites);
        $link = $db->prepare('INSERT INTO game_research_prerequisites (research_node_id, prerequisite_node_id) VALUES (?, ?)');
        $link->execute([$researchNodeId, $prerequisiteId]);
    }
    $db->commit();

    if (!$alreadyLinked) {
        $names = [];
        foreach ($nodeRows as $node) $names[(int)$node['id']] = $node['name'];
        pw_log_admin_activity('research_protocol_connected', 'Connected research prerequisite "' . $names[$prerequisiteId] . '" to "' . $names[$researchNodeId] . '".', $admin);
    }
    pw_json(['ok' => true, 'created' => !$alreadyLinked]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not connect those research protocols.', 409);
}
