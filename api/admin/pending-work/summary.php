<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_admin();

$db = pw_db();

$stmt = $db->query(
    'SELECT COUNT(*) AS c
     FROM dispatch_entries d
     LEFT JOIN dispatch_translations dt ON dt.dispatch_id = d.id
     WHERE dt.id IS NULL'
);
$row = $stmt->fetch();

$reportsStmt = $db->query(
    "SELECT COUNT(*) AS c FROM content_reports WHERE status = 'open'"
);
$reportsRow = $reportsStmt->fetch();

pw_json([
    'ok' => true,
    'dispatches_awaiting_translation' => (int)$row['c'],
    'active_topic_reports' => (int)$reportsRow['c'],
]);
