<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('dashboards.view_home');

$db = pw_db();

$stmt = $db->query(
    'SELECT COUNT(*) AS c
     FROM dispatch_entries d
     LEFT JOIN dispatch_translations dt ON dt.dispatch_id = d.id
     WHERE dt.id IS NULL'
);
$row = $stmt->fetch();

$reportsStmt = $db->query(
    "SELECT
        SUM(target_type IN ('topic', 'comment')) AS topic_reports,
        SUM(target_type = 'news_comment') AS news_comment_reports
     FROM content_reports
     WHERE status = 'open'"
);
$reportsRow = $reportsStmt->fetch();

$privacyCount = 0;
try {
    $privacyCount = (int)$db->query(
        "SELECT COUNT(*) AS c FROM privacy_requests WHERE status IN ('submitted', 'identity_check', 'in_progress')"
    )->fetch()['c'];
} catch (PDOException $e) {
    // The feature can be deployed before its one-off database migration.
}

pw_json([
    'ok' => true,
    'dispatches_awaiting_translation' => (int)$row['c'],
    'active_topic_reports' => (int)($reportsRow['topic_reports'] ?? 0),
    'active_news_comment_reports' => (int)($reportsRow['news_comment_reports'] ?? 0),
    'pending_privacy_requests' => $privacyCount,
]);
