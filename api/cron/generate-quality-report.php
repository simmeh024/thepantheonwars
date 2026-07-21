<?php
/**
 * Cron-only weekly endpoint: generates a Dispatch Translation quality report
 * covering the past 7 days (see api/dispatch-quality-report.php for what it
 * actually computes) and stores it in dispatch_quality_reports for the
 * Translation Quality admin page to display. Advisory only -- never changes
 * a translation, confidence score, or the auto-publish gate.
 *
 * Invoked by a cPanel Cron Job hitting this URL with
 * ?key=<CRON_SAMPLE_KEY> once a week (see CLAUDE.md's Cron jobs section for
 * the exact command). Reuses the same shared secret as the other cron
 * endpoints rather than adding a fourth constant for the same trust
 * boundary.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../dispatch-quality-report.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!defined('CRON_SAMPLE_KEY') || CRON_SAMPLE_KEY === '' || !hash_equals(CRON_SAMPLE_KEY, $providedKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

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
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'dispatch_quality_reports migration may not be applied yet.']);
    exit;
}

echo json_encode(['ok' => true, 'report' => $report]);
