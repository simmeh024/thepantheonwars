<?php
/**
 * Shared data builder for the Home-page BH-4 Task Advisor. The bundled
 * summary passes in the already-computed system signals so it does not make
 * a second GitHub/database/storage health check just for this card.
 */

const PW_ADVISOR_ACTIONS = [
    'system_status' => ['label' => 'Open System Status', 'section' => 'system-status'],
    'topic_reports' => ['label' => 'Review reports', 'section' => 'topic-reports'],
    'dispatch_translations' => ['label' => 'Open translations', 'section' => 'dispatch-translations'],
];

function pw_build_task_advisor(array $signals) {
    $criticalAlerts = $signals['critical_alerts'];
    $criticalPriority = $signals['critical_type_priority'];
    $reportsCount = $signals['reports_count'];
    $reportsAgeMinutes = $signals['reports_age_minutes'];
    $translationsCount = $signals['translations_count'];
    $actions = $signals['actions'];

    $reportsTask = null;
    if ($reportsCount > 0) {
        $reportsTask = [
            'type' => 'topic_reports',
            'priority' => 'high',
            'title' => $reportsCount === 1 ? 'Review 1 unresolved topic report' : 'Review ' . $reportsCount . ' unresolved topic reports',
            'reason' => 'Community moderation requires attention.',
            'count' => $reportsCount,
            'oldest_age_minutes' => $reportsAgeMinutes,
            'action_label' => $actions['topic_reports']['label'],
            'action_url' => $actions['topic_reports']['section'],
        ];
    }

    $translationsTask = null;
    if ($translationsCount > 0) {
        $translationsTask = [
            'type' => 'dispatch_translations',
            'priority' => 'normal',
            'title' => $translationsCount === 1
                ? '1 dispatch requires an end-user translation'
                : $translationsCount . ' dispatches require end-user translations',
            'reason' => 'Public development records require accessible summaries.',
            'count' => $translationsCount,
            'action_label' => $actions['dispatch_translations']['label'],
            'action_url' => $actions['dispatch_translations']['section'],
        ];
    }

    if (!empty($criticalAlerts)) {
        foreach ($criticalPriority as $type) {
            if (isset($criticalAlerts[$type])) {
                $alert = $criticalAlerts[$type];
                $primary = [
                    'type' => $alert['type'],
                    'priority' => 'critical',
                    'title' => $alert['title'],
                    'reason' => $alert['reason'],
                    'severity' => $alert['severity'],
                    'detected_at' => $alert['detected_at'],
                    'action_label' => $actions['system_status']['label'],
                    'action_url' => $alert['action_url'],
                ];
                return [
                    'primary' => $primary,
                    'secondary' => $reportsTask ?: $translationsTask,
                    'active_alert_count' => count($criticalAlerts),
                ];
            }
        }
    }

    if ($reportsTask) {
        return ['primary' => $reportsTask, 'secondary' => $translationsTask, 'active_alert_count' => 0];
    }
    if ($translationsTask) {
        return ['primary' => $translationsTask, 'secondary' => null, 'active_alert_count' => 0];
    }
    return [
        'primary' => [
            'type' => 'clear',
            'priority' => 'clear',
            'title' => 'No immediate administrative action required',
            'reason' => 'All monitored queues are currently clear.',
            'count' => 0,
            'action_label' => null,
            'action_url' => null,
        ],
        'secondary' => null,
        'active_alert_count' => 0,
    ];
}

function pw_collect_task_advisor($db, array $systemSignals) {
    $criticalStmt = $db->query(
        "SELECT COUNT(*) AS c FROM users WHERE role IN ('admin','moderator') AND failed_login_attempts >= 3"
    );
    $criticalLogins = (int)$criticalStmt->fetch()['c'];
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $criticalPriority = ['security', 'database', 'database_load', 'sql_performance', 'forum', 'dispatch_sync', 'storage', 'github'];
    $criticalAlerts = [];

    if ($criticalLogins > 0) {
        $criticalAlerts['security'] = [
            'type' => 'security',
            'title' => $criticalLogins === 1
                ? '1 staff account has repeated failed login attempts'
                : $criticalLogins . ' staff accounts have repeated failed login attempts',
            'reason' => 'An admin or moderator account has 3 or more consecutive failed sign-in attempts.',
            'severity' => 'critical',
            'detected_at' => $now,
            'action_url' => PW_ADVISOR_ACTIONS['system_status']['section'],
        ];
    }

    $systemAlertSpecs = [
        'database' => ['type' => 'database', 'title' => 'Database is unreachable', 'reason' => 'The System Status database check is currently failing.'],
        'db_load' => ['type' => 'database_load', 'title' => 'Database is under heavy load', 'reason' => 'A routine query is taking longer than expected (' . $systemSignals['db_load']['label'] . ').'],
        'forum' => ['type' => 'forum', 'title' => 'The community forum is offline', 'reason' => 'The forum storage check is currently failing.'],
        'dispatch_sync' => ['type' => 'dispatch_sync', 'title' => 'Dispatch sync is unreachable', 'reason' => 'Could not verify whether dispatch entries are in sync with GitHub.'],
        'avatar_storage' => ['type' => 'storage', 'title' => 'Avatar storage is nearing capacity', 'reason' => 'Avatar storage has reached ' . $systemSignals['avatar_storage']['pct'] . '% of its budget.'],
        'github' => ['type' => 'github', 'title' => 'GitHub repository is unreachable', 'reason' => 'The GitHub API check for the main branch is currently failing.'],
    ];
    foreach ($systemAlertSpecs as $signalKey => $spec) {
        if ($systemSignals[$signalKey]['status'] !== 'bad') {
            continue;
        }
        $criticalAlerts[$spec['type']] = [
            'type' => $spec['type'],
            'title' => $spec['title'],
            'reason' => $spec['reason'],
            'severity' => 'critical',
            'detected_at' => $now,
            'action_url' => PW_ADVISOR_ACTIONS['system_status']['section'],
        ];
    }

    // Application-side query telemetry is intentionally optional: deployments
    // remain healthy until migration_sql_performance_monitoring.sql has been
    // run. A single >=2s query is immediately actionable; so is a recurring
    // slow-query burst (five >=500ms queries in an hour), which is a stronger
    // signal than one benign cold-cache request.
    try {
        $sqlRow = $db->query(
            "SELECT COUNT(*) AS slow_count, MAX(execution_ms) AS max_ms,
                    SUBSTRING_INDEX(GROUP_CONCAT(endpoint ORDER BY execution_ms DESC), ',', 1) AS endpoint
             FROM sql_performance_logs
             WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 1 HOUR)
               AND execution_ms >= 500"
        )->fetch();
        $slowCount = (int)($sqlRow['slow_count'] ?? 0);
        $maxMs = (float)($sqlRow['max_ms'] ?? 0);
        if ($maxMs >= 2000 || $slowCount >= 5) {
            $endpoint = !empty($sqlRow['endpoint']) ? $sqlRow['endpoint'] : 'an application endpoint';
            $criticalAlerts['sql_performance'] = [
                'type' => 'sql_performance',
                'title' => $maxMs >= 2000
                    ? 'A database query exceeded 2 seconds'
                    : $slowCount . ' slow database queries in the last hour',
                'reason' => $endpoint . ' generated the most expensive recent SQL activity ('
                    . round($maxMs, 1) . ' ms peak). Review SQL Performance in System Status.',
                'severity' => 'critical',
                'detected_at' => $now,
                'action_url' => PW_ADVISOR_ACTIONS['system_status']['section'],
            ];
        }
    } catch (Exception $e) {
        // The optional diagnostics table may not exist on an older deployment.
    }

    $reportsRow = $db->query(
        "SELECT COUNT(*) AS c, MIN(created_at) AS oldest_at FROM content_reports WHERE status = 'open'"
    )->fetch();
    $reportsCount = (int)$reportsRow['c'];
    $oldestReportAgeMinutes = $reportsRow['oldest_at']
        ? max(0, (int)floor((time() - strtotime($reportsRow['oldest_at'] . ' UTC')) / 60))
        : null;

    $translationsCount = (int)$db->query(
        'SELECT COUNT(*) AS c FROM dispatch_entries d LEFT JOIN dispatch_translations dt ON dt.dispatch_id = d.id WHERE dt.id IS NULL'
    )->fetch()['c'];

    $result = pw_build_task_advisor([
        'critical_alerts' => $criticalAlerts,
        'critical_type_priority' => $criticalPriority,
        'reports_count' => $reportsCount,
        'reports_age_minutes' => $oldestReportAgeMinutes,
        'translations_count' => $translationsCount,
        'actions' => PW_ADVISOR_ACTIONS,
    ]);

    return [
        'generated_at' => $now,
        'critical_events' => $criticalLogins,
        'primary' => $result['primary'],
        'secondary' => $result['secondary'],
        'overview' => [
            'topic_reports' => $reportsCount,
            'dispatch_translations' => $translationsCount,
            'system_alerts' => count($criticalAlerts),
        ],
    ];
}
