<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$entityType = isset($input['entity_type']) ? (string)$input['entity_type'] : '';
$entityId = isset($input['entity_id']) ? (int)$input['entity_id'] : 0;
if (!in_array($entityType, ['world', 'overlord'], true) || $entityId <= 0) pw_error('Unknown discovery.', 422);

$db = pw_db();
try {
    $targetTable = $entityType === 'world' ? 'worlds' : 'overlords';
    $target = $db->prepare("SELECT name FROM $targetTable WHERE id = ? AND status = 'available'");
    $target->execute([$entityId]);
    $row = $target->fetch();
    if (!$row) pw_error('That lore record is unavailable.', 404);

    $db->beginTransaction();
    $discover = $db->prepare('INSERT IGNORE INTO user_lore_discoveries (user_id, entity_type, entity_id) VALUES (?, ?, ?)');
    $discover->execute([(int)$user['id'], $entityType, $entityId]);
    $awarded = $discover->rowCount() === 1 ? pw_award_reputation($db, (int)$user['id'], 2, 'lore_discovery', [
        'source_type' => $entityType,
        'source_id' => $entityId,
        'note' => 'First visit: ' . $row['name'],
    ]) : 0;
    $db->commit();
    pw_json(['ok' => true, 'awarded' => $awarded, 'already_discovered' => $awarded === 0]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error('Could not record this discovery. The lore discovery migration may still need to be run.', 503);
}
