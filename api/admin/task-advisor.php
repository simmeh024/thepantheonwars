<?php
/**
 * BH-4 Task Advisor: a deterministic (not AI-generated) "what should I work
 * on next" recommendation for the Home dashboard's BH-4 card. Rule-based
 * priority, in order:
 *
 *   1. An active critical system issue (reused from the exact same checks
 *      System Status already runs -- see pw_build_system_signals() in
 *      system-status/status-helpers.php -- plus the same repeated-failed-
 *      login "critical events" signal home-bh4/summary.php already surfaces).
 *   2. Unresolved topic reports (content_reports.status = 'open' -- same
 *      table/column pending-work/summary.php already counts).
 *   3. Pending dispatch translations (dispatch_entries LEFT JOIN
 *      dispatch_translations WHERE dt.id IS NULL -- same query pending-work/
 *      summary.php already runs).
 *   4. Clear state if none of the above apply.
 *
 * Gated on dashboards.view_home, matching every other Home-card summary
 * endpoint (home-bh4, pending-work, community-metrics, site-stats) -- this
 * card lives on Home, so it follows that same permission rather than also
 * requiring topic_reports.view/dispatch_translations.view/
 * dashboards.view_system_status individually; an admin who can see the Home
 * dashboard sees the advisor's aggregate counts, the same way Pending Work's
 * counts already work today.
 *
 * Multiple simultaneous critical signals are broken by a fixed type-priority
 * order (see CRITICAL_TYPE_PRIORITY below), not by "oldest first" -- none of
 * these six signals are persisted with a real detection timestamp (they're
 * live checks re-run on every request), so there is no genuine "oldest" to
 * sort by. detected_at on every alert is simply "now" (this request's time).
 * This is a documented MVP simplification, not an oversight.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/system-status/status-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pw_error('Method not allowed.', 405);
}

pw_require_permission('dashboards.view_home');
$db = pw_db();

// --- Fixed, server-side action mapping --------------------------------------
// Never derived from user/database input -- these are the only three
// section tokens the advisor can ever point at, matching the admin
// console's existing client-side showSection(name) SPA navigation (there
// are no real server routes for admin sections).
const PW_ADVISOR_ACTIONS = [
    'system_status' => ['label' => 'Open System Status', 'section' => 'system-status'],
    'topic_reports' => ['label' => 'Review reports', 'section' => 'topic-reports'],
    'dispatch_translations' => ['label' => 'Open translations', 'section' => 'dispatch-translations'],
];

// --- Signal 1: critical system issues ---------------------------------------
$signals = pw_build_system_signals($db);

$criticalStmt = $db->query(
    "SELECT COUNT(*) AS c FROM users WHERE role IN ('admin','moderator') AND failed_login_attempts >= 3"
);
$criticalLogins = (int)$criticalStmt->fetch()['c'];

$now = gmdate('Y-m-d\TH:i:s\Z');

// Fixed tie-break order when more than one critical signal is active at
// once: security first (compromised-account risk), then whether the
// database itself is reachable/responsive, then whether public-facing
// services are up, then sync/storage housekeeping last.
$CRITICAL_TYPE_PRIORITY = ['security', 'database', 'database_load', 'forum', 'dispatch_sync', 'storage', 'github'];

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
if ($signals['database']['status'] === 'bad') {
    $criticalAlerts['database'] = [
        'type' => 'database',
        'title' => 'Database is unreachable',
        'reason' => 'The System Status database check is currently failing.',
        'severity' => 'critical',
        'detected_at' => $now,
        'action_url' => PW_ADVISOR_ACTIONS['system_status']['section'],
    ];
}
if ($signals['db_load']['status'] === 'bad') {
    $criticalAlerts['database_load'] = [
        'type' => 'database_load',
        'title' => 'Database is under heavy load',
        'reason' => 'A routine query is taking longer than expected (' . $signals['db_load']['label'] . ').',
        'severity' => 'critical',
        'detected_at' => $now,
        'action_url' => PW_ADVISOR_ACTIONS['system_status']['section'],
    ];
}
if ($signals['forum']['status'] === 'bad') {
    $criticalAlerts['forum'] = [
        'type' => 'forum',
        'title' => 'The community forum is offline',
        'reason' => 'The forum storage check is currently failing.',
        'severity' => 'critical',
        'detected_at' => $now,
        'action_url' => PW_ADVISOR_ACTIONS['system_status']['section'],
    ];
}
if ($signals['dispatch_sync']['status'] === 'bad') {
    $criticalAlerts['dispatch_sync'] = [
        'type' => 'dispatch_sync',
        'title' => 'Dispatch sync is unreachable',
        'reason' => 'Could not verify whether dispatch entries are in sync with GitHub.',
        'severity' => 'critical',
        'detected_at' => $now,
        'action_url' => PW_ADVISOR_ACTIONS['system_status']['section'],
    ];
}
if ($signals['avatar_storage']['status'] === 'bad') {
    $criticalAlerts['storage'] = [
        'type' => 'storage',
        'title' => 'Avatar storage is nearing capacity',
        'reason' => 'Avatar storage has reached ' . $signals['avatar_storage']['pct'] . '% of its budget.',
        'severity' => 'critical',
        'detected_at' => $now,
        'action_url' => PW_ADVISOR_ACTIONS['system_status']['section'],
    ];
}
if ($signals['github']['status'] === 'bad') {
    $criticalAlerts['github'] = [
        'type' => 'github',
        'title' => 'GitHub repository is unreachable',
        'reason' => 'The GitHub API check for the main branch is currently failing.',
        'severity' => 'critical',
        'detected_at' => $now,
        'action_url' => PW_ADVISOR_ACTIONS['system_status']['section'],
    ];
}

// --- Signal 2: unresolved topic reports -------------------------------------
$reportsCountStmt = $db->query("SELECT COUNT(*) AS c FROM content_reports WHERE status = 'open'");
$reportsCount = (int)$reportsCountStmt->fetch()['c'];

$oldestReportStmt = $db->query("SELECT created_at FROM content_reports WHERE status = 'open' ORDER BY created_at ASC LIMIT 1");
$oldestReportRow = $oldestReportStmt->fetch();
$oldestReportAgeMinutes = null;
if ($oldestReportRow) {
    $oldestReportAgeMinutes = max(0, (int)floor((time() - strtotime($oldestReportRow['created_at'] . ' UTC')) / 60));
}

// --- Signal 3: pending dispatch translations --------------------------------
$translationsCountStmt = $db->query(
    'SELECT COUNT(*) AS c FROM dispatch_entries d LEFT JOIN dispatch_translations dt ON dt.dispatch_id = d.id WHERE dt.id IS NULL'
);
$translationsCount = (int)$translationsCountStmt->fetch()['c'];

// --- Resolver ----------------------------------------------------------------
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
        $primaryType = null;
        foreach ($criticalPriority as $type) {
            if (isset($criticalAlerts[$type])) {
                $primaryType = $type;
                break;
            }
        }
        $alert = $criticalAlerts[$primaryType];
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
        $secondary = $reportsTask ?: $translationsTask;
        return ['primary' => $primary, 'secondary' => $secondary, 'active_alert_count' => count($criticalAlerts)];
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

$result = pw_build_task_advisor([
    'critical_alerts' => $criticalAlerts,
    'critical_type_priority' => $CRITICAL_TYPE_PRIORITY,
    'reports_count' => $reportsCount,
    'reports_age_minutes' => $oldestReportAgeMinutes,
    'translations_count' => $translationsCount,
    'actions' => PW_ADVISOR_ACTIONS,
]);

pw_json([
    'ok' => true,
    'generated_at' => $now,
    'primary' => $result['primary'],
    'secondary' => $result['secondary'],
    'overview' => [
        'topic_reports' => $reportsCount,
        'dispatch_translations' => $translationsCount,
        'system_alerts' => count($criticalAlerts),
    ],
]);
