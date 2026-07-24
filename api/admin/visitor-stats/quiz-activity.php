<?php
/**
 * Quiz Activity card for Visitor Statistics. Starts, completions and the
 * outcome mix include anonymous guest attempts as well as member attempts;
 * the source table contains only one-way browser hashes and final outcomes.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('analytics.view');
$db = pw_db();

$range = isset($_GET['range']) ? (int)$_GET['range'] : 30;
if (!in_array($range, [7, 30, 90], true)) {
    $range = 30;
}

$includeAdmin = isset($_GET['include_admin']) && $_GET['include_admin'] === '1';
$adminFilterSql = $includeAdmin ? '1=1' : pw_admin_view_filter_sql('qa');

try {
    $summaryStmt = $db->prepare(
        "SELECT COUNT(*) AS starts,
                COALESCE(SUM(qa.completed_at IS NOT NULL), 0) AS completions,
                COALESCE(SUM(qa.completed_at IS NOT NULL AND qa.user_id IS NULL), 0) AS guest_completions,
                COALESCE(SUM(qa.completed_at IS NOT NULL AND qa.user_id IS NOT NULL), 0) AS member_completions
         FROM quiz_activity qa
         WHERE qa.started_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
           AND $adminFilterSql"
    );
    $summaryStmt->execute([$range]);
    $summary = $summaryStmt->fetch();

    $resultStmt = $db->prepare(
        "SELECT qa.overlord_result,
                COUNT(*) AS completions,
                COALESCE(SUM(qa.user_id IS NULL), 0) AS guest_completions
         FROM quiz_activity qa
         WHERE qa.started_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
           AND qa.completed_at IS NOT NULL
           AND qa.overlord_result IS NOT NULL
           AND $adminFilterSql
         GROUP BY qa.overlord_result
         ORDER BY completions DESC, qa.overlord_result ASC"
    );
    $resultStmt->execute([$range]);
    $results = array_map(function ($row) {
        return [
            'overlord' => $row['overlord_result'],
            'completions' => (int)$row['completions'],
            'guest_completions' => (int)$row['guest_completions'],
        ];
    }, $resultStmt->fetchAll());
} catch (PDOException $e) {
    // The dashboard remains healthy when code is deployed ahead of the manual
    // migration. The card makes the required next step explicit instead.
    pw_json([
        'ok' => true,
        'available' => false,
        'range' => $range,
        'include_admin' => $includeAdmin,
        'starts' => 0,
        'completions' => 0,
        'guest_completions' => 0,
        'member_completions' => 0,
        'completion_rate' => 0,
        'results' => [],
    ]);
}

$starts = (int)$summary['starts'];
$completions = (int)$summary['completions'];

pw_json([
    'ok' => true,
    'available' => true,
    'range' => $range,
    'include_admin' => $includeAdmin,
    'starts' => $starts,
    'completions' => $completions,
    'guest_completions' => (int)$summary['guest_completions'],
    'member_completions' => (int)$summary['member_completions'],
    'completion_rate' => $starts > 0 ? (int)round(($completions / $starts) * 100) : 0,
    'results' => $results,
]);
