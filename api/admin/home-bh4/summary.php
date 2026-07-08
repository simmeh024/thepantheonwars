<?php
/**
 * Feeds the "Welcome back" BH-4 card at the top of the admin console's Home
 * page. Everything here is scoped to "since your last session": we find the
 * current admin's previous login (the second-most-recent 'login' row in
 * admin_activity_log for this user_id -- the most recent row is the login
 * that started *this* session), then count what's happened system-wide
 * since that point. If there's no previous login on record (brand new
 * admin, or logging only just started tracking this), we fall back to the
 * single login row we do have so the counts land near zero rather than
 * showing the account's entire history on day one.
 *
 * "Critical events" is intentionally simple for now, per spec: any admin or
 * moderator account currently sitting at 3+ failed login attempts (the same
 * counter api/login.php increments and resets -- see users.failed_login_attempts).
 * That's a live snapshot, not a time-windowed count, since the counter itself
 * resets to 0 on the next successful login.
 */
require_once __DIR__ . '/../../helpers.php';

$user = pw_require_admin();
$db = pw_db();

$stmt = $db->prepare(
    "SELECT created_at FROM admin_activity_log WHERE user_id = ? AND action = 'login' ORDER BY created_at DESC LIMIT 2"
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
    "SELECT COUNT(*) AS c FROM admin_activity_log WHERE action = 'login' AND created_at > ?"
);
$loginsStmt->execute([$since]);
$adminLogins = (int)$loginsStmt->fetch()['c'];

$criticalStmt = $db->query(
    "SELECT COUNT(*) AS c FROM users WHERE role IN ('admin','moderator') AND failed_login_attempts >= 3"
);
$criticalEvents = (int)$criticalStmt->fetch()['c'];

pw_json([
    'ok' => true,
    'display_name' => $user['display_name'],
    'since' => $since,
    'dispatches_classified' => $dispatchesClassified,
    'translations_completed' => $translationsCompleted,
    'admin_logins' => $adminLogins,
    'critical_events' => $criticalEvents,
]);
