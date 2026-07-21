<?php
/**
 * Manual trigger for the same weekly report the cron job produces (see
 * api/cron/generate-quality-report.php), so an admin can generate one
 * on demand without waiting for the scheduled run -- useful right after
 * setting this up, or after a burst of new ratings.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../dispatch-quality-report.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

pw_require_permission('dispatch_translations.edit');
$input = pw_input();
pw_require_csrf($input);

$db = pw_db();

$windowEnd = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$windowStart = $windowEnd->sub(new DateInterval('P7D'));

$report = pw_dispatch_generate_quality_report(
    $db,
    $windowStart->format('Y-m-d H:i:s'),
    $windowEnd->format('Y-m-d H:i:s')
);

try {
    $stmt = $db->prepare(
        'INSERT INTO dispatch_quality_reports (window_start, window_end, summary_json)
         VALUES (?, ?, ?)'
    );
    $stmt->execute([
        $windowStart->format('Y-m-d'),
        $windowEnd->format('Y-m-d'),
        json_encode($report),
    ]);
    $reportId = (int)$db->lastInsertId();
} catch (PDOException $e) {
    pw_error('Quality reports are not set up yet. Run the pending SQL migration first.', 500);
}

pw_json(['ok' => true, 'report_id' => $reportId, 'report' => $report]);
