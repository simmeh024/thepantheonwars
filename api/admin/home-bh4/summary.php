<?php
/**
 * Feeds the "Welcome back" BH-4 card at the top of the admin console's Home
 * page. Everything here is scoped to this authenticated PHP session. The
 * baseline is captured on its first Home request and stored in the session,
 * so signing in again starts a fresh, accurate change count without relying
 * on activity-log timestamp ordering or legacy login rows.
 *
 * Critical events are shared with the Home summary through the task advisor:
 * account-security alerts and critical infrastructure signals (including the
 * Dispatch worker and transactional mail transport) use one prioritised
 * source, so BH-4 shows the same condition everywhere in the console.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../system-status/status-helpers.php';
require_once __DIR__ . '/../task-advisor-helpers.php';

$user = pw_require_permission('dashboards.view_home');
$db = pw_db();

$since = isset($_SESSION['pw_bh4_session_started_at'])
    ? $_SESSION['pw_bh4_session_started_at']
    : $db->query('SELECT NOW() AS now')->fetch()['now'];
$_SESSION['pw_bh4_session_started_at'] = $since;

$dispatchesStmt = $db->prepare(
    "SELECT COUNT(*) AS c FROM admin_activity_log WHERE action = 'category_edited' AND created_at > ?"
);
$dispatchesStmt->execute([$since]);
$dispatchesClassified = (int)$dispatchesStmt->fetch()['c'];

$translationsStmt = $db->prepare(
    "SELECT COUNT(*) AS c FROM admin_activity_log WHERE action IN ('translation_added', 'translation_updated') AND created_at > ?"
);
$translationsStmt->execute([$since]);
$translationsCompleted = (int)$translationsStmt->fetch()['c'];

$membersStmt = $db->prepare('SELECT COUNT(*) AS c FROM users WHERE created_at > ?');
$membersStmt->execute([$since]);
$newMembers = (int)$membersStmt->fetch()['c'];

$loginsStmt = $db->prepare(
    "SELECT COUNT(*) AS c
     FROM admin_activity_log
     WHERE action IN ('login_ok', 'login') AND created_at > ?"
);
$loginsStmt->execute([$since]);
$adminLogins = (int)$loginsStmt->fetch()['c'];

$advisor = pw_collect_task_advisor($db, pw_build_system_signals($db));
$criticalEvents = (int)$advisor['critical_events'];
$criticalSummary = (!empty($advisor['primary']) && ($advisor['primary']['priority'] ?? '') === 'critical')
    ? ($advisor['primary']['title'] ?? null)
    : null;

pw_json([
    'ok' => true,
    'display_name' => $user['display_name'],
    'since' => $since,
    'dispatches_classified' => $dispatchesClassified,
    'translations_completed' => $translationsCompleted,
    'new_members' => $newMembers,
    'admin_logins' => $adminLogins,
    'critical_events' => $criticalEvents,
    'critical_summary' => $criticalSummary,
]);
