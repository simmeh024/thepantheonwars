<?php
/**
 * Feeds the "Welcome back" BH-4 card at the top of the admin console's Home
 * page. Everything here is scoped to "since your last session": we find the
 * current admin's previous successful-login row (the second-most-recent
 * 'login_ok' row; legacy deployments may still have older 'login' rows) in
 * admin_activity_log for this user_id -- the most recent row is the login
 * that started *this* session), then count what's happened system-wide
 * since that point. If there's no previous login on record (brand new
 * admin, or logging only just started tracking this), we fall back to the
 * single login row we do have so the counts land near zero rather than
 * showing the account's entire history on day one.
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

$stmt = $db->prepare(
    "SELECT id, created_at
     FROM admin_activity_log
     WHERE user_id = ? AND action IN ('login_ok', 'login')
     ORDER BY created_at DESC, id DESC
     LIMIT 2"
);
$stmt->execute([$user['id']]);
$logins = $stmt->fetchAll();

$since = null;
if (count($logins) >= 2) {
    $since = $logins[1]['created_at'];
} elseif (count($logins) === 1) {
    $since = $logins[0]['created_at'];
}
if ($since === null) {
    $since = '1970-01-01 00:00:00';
}

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
    'admin_logins' => $adminLogins,
    'critical_events' => $criticalEvents,
    'critical_summary' => $criticalSummary,
]);
