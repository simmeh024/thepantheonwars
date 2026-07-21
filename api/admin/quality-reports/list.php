<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('dispatch_translations.view');

$db = pw_db();

try {
    $rows = $db->query(
        'SELECT id, window_start, window_end, summary_json, status, generated_at, reviewed_at, reviewed_by_username
         FROM dispatch_quality_reports
         ORDER BY generated_at DESC
         LIMIT 20'
    )->fetchAll();
} catch (PDOException $e) {
    pw_json(['ok' => true, 'reports' => []]);
    exit;
}

$out = array_map(function ($row) {
    $summary = json_decode((string)$row['summary_json'], true);
    return [
        'id' => (int)$row['id'],
        'window_start' => $row['window_start'],
        'window_end' => $row['window_end'],
        'status' => $row['status'],
        'generated_at' => $row['generated_at'],
        'reviewed_at' => $row['reviewed_at'],
        'reviewed_by_username' => $row['reviewed_by_username'],
        'summary' => is_array($summary) ? $summary : null,
    ];
}, $rows);

pw_json(['ok' => true, 'reports' => $out]);
