<?php
/**
 * Bundled data source for the Admin Home page. It replaces the per-card
 * request fan-out with one permission-aware response and shares the single
 * system-health pass between the System Status card and the BH-4 advisor.
 *
 * A session-scoped 15-second cache absorbs repeat renders and overlapping
 * navigation. Callers may use ?fresh=1 after a manual dashboard action.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/loc-stats-helpers.php';
require_once __DIR__ . '/system-status/status-helpers.php';
require_once __DIR__ . '/task-advisor-helpers.php';
require_once __DIR__ . '/../repo-languages-helpers.php';
require_once __DIR__ . '/community-pulse/community-pulse-helpers.php';
require_once __DIR__ . '/../dispatch-translation-drafts.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dashboards.view_home');
$forceFresh = isset($_GET['fresh']) && $_GET['fresh'] === '1';
$cacheTtl = 15;
$cache = isset($_SESSION['pw_home_summary_cache']) ? $_SESSION['pw_home_summary_cache'] : null;
if (!$forceFresh && is_array($cache) && isset($cache['created_at'], $cache['payload'])
    && (time() - (int)$cache['created_at']) < $cacheTtl) {
    $payload = $cache['payload'];
    $payload['cached'] = true;
    pw_json($payload);
}

$db = pw_db();
$canViewIp = pw_has_permission($adminUser, 'dashboards.view_ip_addresses');

// Latest activity is independently permission-gated in the existing API.
$activity = ['ok' => false];
if (pw_has_permission($adminUser, 'dashboards.view_audit_log')) {
    $activityRows = $db->query(
        'SELECT id, username, action, description, ip_address, created_at
         FROM admin_activity_log
         ORDER BY created_at DESC, id DESC
         LIMIT 5'
    )->fetchAll();
    $activity = [
        'ok' => true,
        'entries' => array_map(function ($row) use ($canViewIp) {
            return [
                'id' => (int)$row['id'],
                'username' => $row['username'],
                'action' => $row['action'],
                'description' => $row['description'],
                'ip_address' => $canViewIp ? $row['ip_address'] : null,
                'created_at' => $row['created_at'],
            ];
        }, $activityRows),
    ];
}

$pendingRow = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM dispatch_entries d LEFT JOIN dispatch_translations dt ON dt.dispatch_id = d.id WHERE dt.id IS NULL) AS translations,
        (SELECT COUNT(*) FROM content_reports WHERE status = 'open') AS reports"
)->fetch();
$privacyPending = 0;
try {
    $privacyPending = (int)$db->query(
        "SELECT COUNT(*) AS c FROM privacy_requests WHERE status IN ('submitted', 'identity_check', 'in_progress')"
    )->fetch()['c'];
} catch (PDOException $e) {
    // Migration may be run after code deployment; keep the dashboard available.
}
$pendingWork = [
    'ok' => true,
    'dispatches_awaiting_translation' => (int)$pendingRow['translations'],
    'active_topic_reports' => (int)$pendingRow['reports'],
    'pending_privacy_requests' => $privacyPending,
];

$draftRows = $db->query(
    'SELECT book_number, title, writing_stage, COUNT(*) OVER () AS draft_count
     FROM books
     WHERE writing_stage < 15
     ORDER BY writing_stage DESC, book_number ASC
     LIMIT 3'
)->fetchAll();
$draftCount = $draftRows ? (int)$draftRows[0]['draft_count'] : 0;
$contentDrafts = [
    'ok' => true,
    'draft_count' => $draftCount,
    'drafts' => array_map(function ($row) {
        return [
            'book_number' => (int)$row['book_number'],
            'title' => $row['title'],
            'writing_stage' => (int)$row['writing_stage'],
        ];
    }, $draftRows),
];

$securityRow = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= NOW() - INTERVAL 24 HOUR) AS failed_logins,
        (SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()) AS locked_accounts,
        (SELECT COUNT(*) FROM users WHERE banned_at IS NOT NULL AND (banned_until IS NULL OR banned_until > NOW())) AS banned_accounts"
)->fetch();
$security = [
    'ok' => true,
    'failed_logins_24h' => (int)$securityRow['failed_logins'],
    'locked_accounts' => (int)$securityRow['locked_accounts'],
    'banned_accounts' => (int)$securityRow['banned_accounts'],
];

$communityRow = $db->query(
    "SELECT
        SUM(CASE WHEN last_active_at IS NOT NULL AND last_active_at >= NOW() - INTERVAL 24 HOUR AND role = 'member' THEN 1 ELSE 0 END) AS active_members,
        SUM(CASE WHEN last_active_at IS NOT NULL AND last_active_at >= NOW() - INTERVAL 24 HOUR AND role = 'moderator' THEN 1 ELSE 0 END) AS active_moderators,
        SUM(CASE WHEN last_active_at IS NOT NULL AND last_active_at >= NOW() - INTERVAL 24 HOUR AND role = 'admin' THEN 1 ELSE 0 END) AS active_admins,
        SUM(CASE WHEN created_at >= NOW() - INTERVAL 24 HOUR THEN 1 ELSE 0 END) AS new_members,
        SUM(CASE WHEN banned_at IS NOT NULL AND banned_at >= NOW() - INTERVAL 24 HOUR THEN 1 ELSE 0 END) AS banned_members,
        (SELECT COUNT(*) FROM topics WHERE is_deleted = 0 AND created_at >= NOW() - INTERVAL 24 HOUR)
          + (SELECT COUNT(*) FROM comments WHERE is_deleted = 0 AND created_at >= NOW() - INTERVAL 24 HOUR) AS forum_posts
     FROM users"
)->fetch();
$community = [
    'ok' => true,
    'members_active_24h' => (int)$communityRow['active_members'],
    'new_members_24h' => (int)$communityRow['new_members'],
    'banned_24h' => (int)$communityRow['banned_members'],
    'admins_active_24h' => (int)$communityRow['active_admins'],
    'moderators_active_24h' => (int)$communityRow['active_moderators'],
    'forum_posts_24h' => (int)$communityRow['forum_posts'],
];
$communityPulse = pw_get_community_pulse($db);

$siteRow = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM users) AS total_members,
        (SELECT COUNT(*) FROM topics WHERE is_deleted = 0) + (SELECT COUNT(*) FROM comments WHERE is_deleted = 0) AS total_forum_posts,
        (SELECT COUNT(*) FROM dispatch_entries) AS total_dispatches,
        (SELECT COUNT(*) FROM dispatch_entries WHERE DATE(committed_at) = CURDATE()) AS today_dispatches,
        (SELECT tag FROM dispatch_entries WHERE DATE(committed_at) = CURDATE() GROUP BY tag ORDER BY COUNT(*) DESC, tag ASC LIMIT 1) AS most_active_category"
)->fetch();
$siteStats = [
    'ok' => true,
    'total_members' => (int)$siteRow['total_members'],
    'total_forum_posts' => (int)$siteRow['total_forum_posts'],
    'total_dispatches' => (int)$siteRow['total_dispatches'],
    'active_worlds' => 4,
    'active_overlords' => 6,
    'today_dispatches' => (int)$siteRow['today_dispatches'],
    'most_active_category' => $siteRow['most_active_category'],
];
$translationConfidence = pw_get_dispatch_translation_confidence_statistics($db);

$loginRows = $db->prepare(
    "SELECT id, created_at
     FROM admin_activity_log
     WHERE user_id = ? AND action IN ('login_ok', 'login')
     ORDER BY created_at DESC, id DESC
     LIMIT 2"
);
$loginRows->execute([$adminUser['id']]);
$previousLogins = $loginRows->fetchAll();
$since = count($previousLogins) >= 2 ? $previousLogins[1]['created_at']
    : (count($previousLogins) === 1 ? $previousLogins[0]['created_at'] : '1970-01-01 00:00:00');
$bh4Row = $db->prepare(
    "SELECT
        SUM(action = 'category_edited') AS dispatches_classified,
        SUM(action IN ('translation_added', 'translation_updated')) AS translations_completed,
        SUM(action IN ('login_ok', 'login')) AS admin_logins
     FROM admin_activity_log WHERE created_at > ?"
);
$bh4Row->execute([$since]);
$bh4Counts = $bh4Row->fetch();
// The old page queried this once for System Status and again for the advisor.
$systemSignals = pw_build_system_signals($db);
$systemStatus = ['ok' => false];
if (pw_has_permission($adminUser, 'dashboards.view_system_status')) {
    $systemStatus = array_merge(
        ['ok' => true],
        $systemSignals,
        ['checked_at' => gmdate('c')]
    );
}

$advisorData = pw_collect_task_advisor($db, $systemSignals);
$criticalEvents = $advisorData['critical_events'];
unset($advisorData['critical_events']);
$advisor = array_merge(['ok' => true], $advisorData);
$bh4 = [
    'ok' => true,
    'display_name' => $adminUser['display_name'],
    'since' => $since,
    'dispatches_classified' => (int)$bh4Counts['dispatches_classified'],
    'translations_completed' => (int)$bh4Counts['translations_completed'],
    'admin_logins' => (int)$bh4Counts['admin_logins'],
    'critical_events' => $criticalEvents,
];
$locStats = pw_get_loc_stats($db);
$loc = $locStats === null ? ['ok' => false] : array_merge(['ok' => true], $locStats);

pw_ensure_repo_language_snapshot($db);
$languages = array_merge(['ok' => true], pw_latest_repo_language_snapshot($db));

$payload = [
    'ok' => true,
    'cached' => false,
    'generated_at' => gmdate('c'),
    'activity' => $activity,
    'pending_work' => $pendingWork,
    'content_drafts' => $contentDrafts,
    'security' => $security,
    'community' => $community,
    'community_pulse' => $communityPulse,
    'site_stats' => $siteStats,
    'translation_confidence' => $translationConfidence,
    'development_snapshot' => ['loc' => $loc, 'languages' => $languages],
    'bh4' => $bh4,
    'system_status' => $systemStatus,
    'task_advisor' => $advisor,
];
$_SESSION['pw_home_summary_cache'] = ['created_at' => time(), 'payload' => $payload];

pw_json($payload);
