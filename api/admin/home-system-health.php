<?php
/**
 * Deferred, combined health payload for the Admin Home page.
 *
 * The Home summary intentionally excludes this work so normal dashboard data
 * can render without waiting for a cold local spaCy model load. Keeping System
 * Status and BH-4's advisor together here still performs one shared signal
 * collection instead of competing health requests.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/system-status/status-helpers.php';
require_once __DIR__ . '/task-advisor-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dashboards.view_home');
$forceFresh = isset($_GET['fresh']) && $_GET['fresh'] === '1';
$db = pw_db();
$signals = pw_build_system_signals($db, $forceFresh);

$systemStatus = ['ok' => false];
if (pw_has_permission($adminUser, 'dashboards.view_system_status')) {
    $systemStatus = array_merge(
        ['ok' => true],
        $signals,
        ['checked_at' => gmdate('c')]
    );
}

$advisorData = pw_collect_task_advisor($db, $signals);
$criticalEvents = (int)$advisorData['critical_events'];
$criticalSummary = (!empty($advisorData['primary']) && ($advisorData['primary']['priority'] ?? '') === 'critical')
    ? ($advisorData['primary']['title'] ?? null)
    : null;
unset($advisorData['critical_events']);

pw_json([
    'ok' => true,
    'system_status' => $systemStatus,
    'bh4_health' => [
        'critical_events' => $criticalEvents,
        'critical_summary' => $criticalSummary,
    ],
    'task_advisor' => array_merge(['ok' => true], $advisorData),
]);
