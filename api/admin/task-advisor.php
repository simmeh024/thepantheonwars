<?php
/**
 * Standalone endpoint for the Task Advisor's manual refresh control.
 * The bundled Home summary reuses the same collector to avoid duplicate
 * system checks during the normal dashboard refresh.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/system-status/status-helpers.php';
require_once __DIR__ . '/task-advisor-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pw_error('Method not allowed.', 405);
}

pw_require_permission('dashboards.view_home');
$db = pw_db();
$advisor = pw_collect_task_advisor($db, pw_build_system_signals($db));
unset($advisor['critical_events']); // internal reuse detail, not part of this endpoint's contract

pw_json(array_merge(['ok' => true], $advisor));
