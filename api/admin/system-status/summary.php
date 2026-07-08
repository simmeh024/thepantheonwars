<?php
/**
 * Feeds the "System Status" card next to the BH-4 welcome card on the admin
 * Home page. Every item below reports a normalized status of ok/warn/bad/
 * unknown (used by the frontend to color the value green/gold/red) plus a
 * human label. Six signals:
 *
 *  - GitHub Repository: a live HTTP call to the GitHub REST API for the
 *    latest commit on main. If it responds 200, the repo is reachable; we
 *    also keep the sha it returns to cross-check Dispatch Sync below.
 *  - Database: a trivial SELECT against the connection this very request is
 *    already using. In practice, if the DB were actually down, pw_require_admin()
 *    would have thrown before we got here -- this is a defensive check, not
 *    the primary signal, since a hard DB outage surfaces as this whole
 *    request failing rather than a graceful "Unreachable" row.
 *  - Forum: a lightweight query against the topics table, standing in for
 *    "is the community/forum feature's storage reachable."
 *  - Dispatch Sync: compares the sha of the most recently stored dispatch
 *    entry against the sha GitHub reports as the tip of main. They should
 *    always match since every push fires the webhook immediately -- a
 *    mismatch means the webhook missed a push and a manual Re-Sync
 *    (Dispatch Control > Re-Sync) is needed to catch up.
 *  - Site Errors: count of "critical" (Fatal/Parse/Uncaught) PHP error log
 *    entries in the last 24 hours. Links through to the full System Status
 *    page for the complete log. See status-helpers.php for the log
 *    locate/tail/parse logic -- the exact log path varies by hosting setup.
 *  - Avatar Storage: total bytes under uploads/avatars against a 5 GiB soft
 *    budget (see status-helpers.php for the thresholds).
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/status-helpers.php';

pw_require_admin();
$db = pw_db();

// --- GitHub Repository -------------------------------------------------------
$githubStatus = 'bad';
$githubLabel = 'Unreachable';
$latestGithubSha = null;

$ch = curl_init('https://api.github.com/repos/simmeh024/thepantheonwars/commits/main');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'User-Agent: ThePantheonWars-AdminConsole',
        'Accept: application/vnd.github+json',
    ],
    CURLOPT_TIMEOUT => 6,
    CURLOPT_CONNECTTIMEOUT => 4,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response !== false && $httpCode === 200) {
    $data = json_decode($response, true);
    if (is_array($data) && !empty($data['sha'])) {
        $githubStatus = 'ok';
        $githubLabel = 'Connected';
        $latestGithubSha = $data['sha'];
    }
}

// --- Database -----------------------------------------------------------------
$dbStatus = 'ok';
$dbLabel = 'Healthy';
try {
    $db->query('SELECT 1');
} catch (Exception $e) {
    $dbStatus = 'bad';
    $dbLabel = 'Unreachable';
}

// --- Forum ----------------------------------------------------------------------
$forumStatus = 'ok';
$forumLabel = 'Online';
try {
    $db->query('SELECT COUNT(*) FROM topics');
} catch (Exception $e) {
    $forumStatus = 'bad';
    $forumLabel = 'Offline';
}

// --- Dispatch Sync --------------------------------------------------------------
$dispatchSyncStatus = 'unknown';
$dispatchSyncLabel = 'Unknown';
try {
    $localRow = $db->query('SELECT sha FROM dispatch_entries ORDER BY id DESC LIMIT 1')->fetch();
    $localSha = $localRow ? $localRow['sha'] : null;
    if ($githubStatus === 'ok' && $latestGithubSha !== null && $localSha !== null) {
        if ($localSha === $latestGithubSha) {
            $dispatchSyncStatus = 'ok';
            $dispatchSyncLabel = 'Synced';
        } else {
            $dispatchSyncStatus = 'warn';
            $dispatchSyncLabel = 'Out of sync';
        }
    }
} catch (Exception $e) {
    $dispatchSyncStatus = 'bad';
    $dispatchSyncLabel = 'Unreachable';
}

// --- Site Errors ------------------------------------------------------------------
$errorData = pw_load_error_entries();
if (!$errorData['available']) {
    $siteErrorsStatus = 'unknown';
    $siteErrorsLabel = 'Unavailable';
} else {
    $criticalCount = pw_count_recent_critical($errorData['entries'], 24);
    $siteErrorsStatus = $criticalCount > 0 ? 'bad' : 'ok';
    $siteErrorsLabel = $criticalCount . ' critical';
}

// --- Avatar Storage ---------------------------------------------------------------
$avatarStorage = pw_check_avatar_storage();

pw_json([
    'ok' => true,
    'github' => ['status' => $githubStatus, 'label' => $githubLabel],
    'database' => ['status' => $dbStatus, 'label' => $dbLabel],
    'forum' => ['status' => $forumStatus, 'label' => $forumLabel],
    'dispatch_sync' => ['status' => $dispatchSyncStatus, 'label' => $dispatchSyncLabel],
    'site_errors' => ['status' => $siteErrorsStatus, 'label' => $siteErrorsLabel],
    'avatar_storage' => $avatarStorage,
    'checked_at' => gmdate('c'),
]);
