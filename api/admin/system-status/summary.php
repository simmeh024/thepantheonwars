<?php
/**
 * Feeds the "System Status" card next to the BH-4 welcome card on the admin
 * Home page. Reports four independent signals:
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
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_admin();
$db = pw_db();

// --- GitHub Repository -------------------------------------------------------
$githubStatus = 'error';
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
        $githubStatus = 'connected';
        $githubLabel = 'Connected';
        $latestGithubSha = $data['sha'];
    }
}

// --- Database -----------------------------------------------------------------
$dbStatus = 'healthy';
$dbLabel = 'Healthy';
try {
    $db->query('SELECT 1');
} catch (Exception $e) {
    $dbStatus = 'error';
    $dbLabel = 'Unreachable';
}

// --- Forum ----------------------------------------------------------------------
$forumStatus = 'online';
$forumLabel = 'Online';
try {
    $db->query('SELECT COUNT(*) FROM topics');
} catch (Exception $e) {
    $forumStatus = 'error';
    $forumLabel = 'Offline';
}

// --- Dispatch Sync --------------------------------------------------------------
$dispatchSyncStatus = 'unknown';
$dispatchSyncLabel = 'Unknown';
try {
    $localRow = $db->query('SELECT sha FROM dispatch_entries ORDER BY id DESC LIMIT 1')->fetch();
    $localSha = $localRow ? $localRow['sha'] : null;
    if ($githubStatus === 'connected' && $latestGithubSha !== null && $localSha !== null) {
        if ($localSha === $latestGithubSha) {
            $dispatchSyncStatus = 'synced';
            $dispatchSyncLabel = 'Synced';
        } else {
            $dispatchSyncStatus = 'behind';
            $dispatchSyncLabel = 'Out of sync';
        }
    }
} catch (Exception $e) {
    $dispatchSyncStatus = 'error';
    $dispatchSyncLabel = 'Unreachable';
}

pw_json([
    'ok' => true,
    'github' => ['status' => $githubStatus, 'label' => $githubLabel],
    'database' => ['status' => $dbStatus, 'label' => $dbLabel],
    'forum' => ['status' => $forumStatus, 'label' => $forumLabel],
    'dispatch_sync' => ['status' => $dispatchSyncStatus, 'label' => $dispatchSyncLabel],
    'checked_at' => gmdate('c'),
]);
