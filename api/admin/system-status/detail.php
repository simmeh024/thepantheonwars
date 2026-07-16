<?php
/**
 * Feeds the expanded System Status page (System > System Status), organized
 * into four cards on the frontend (GitHub, Security, Database, Storage)
 * even though this endpoint just returns one flat JSON payload. Goes deeper
 * than the compact Home card: GitHub connectivity plus its API rate limit,
 * webhook delivery health (distinct from repo reachability -- this is "has
 * GitHub actually reached us recently", not "can we reach GitHub"), the
 * language-snapshot sync schedule that backs the Development Snapshot
 * language bar, SSL certificate expiry, database load + database size (same
 * checks used on the Home card), and avatar storage (also same check used
 * on the Home card).
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/status-helpers.php';

pw_require_permission('dashboards.view_system_status');
$db = pw_db();

// --- GitHub Repository + API rate limit --------------------------------------
$githubStatus = 'bad';
$githubLabel = 'Unreachable';
$rateLimitStatus = 'unknown';
$rateLimitLabel = 'Unknown';

$ch = curl_init('https://api.github.com/repos/simmeh024/thepantheonwars/commits/main');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_HTTPHEADER => pw_github_curl_headers(),
    CURLOPT_TIMEOUT => 6,
    CURLOPT_CONNECTTIMEOUT => 4,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

if ($response !== false) {
    $headerText = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    if ($httpCode === 200) {
        $data = json_decode($body, true);
        if (is_array($data) && !empty($data['sha'])) {
            $githubStatus = 'ok';
            $githubLabel = 'Connected';
        }
    }

    if (preg_match('/X-RateLimit-Remaining:\s*(\d+)/i', $headerText, $remMatch)
        && preg_match('/X-RateLimit-Limit:\s*(\d+)/i', $headerText, $limMatch)) {
        $remaining = (int)$remMatch[1];
        $limit = (int)$limMatch[1];
        $rateLimitLabel = $remaining . ' / ' . $limit . ' remaining';
        if ($remaining <= 5) {
            $rateLimitStatus = 'bad';
        } elseif ($remaining <= 20) {
            $rateLimitStatus = 'warn';
        } else {
            $rateLimitStatus = 'ok';
        }
    }
}

// --- Webhook delivery ----------------------------------------------------------
// last_webhook_received_at is written by github-webhook.php on every
// successfully-authenticated call (ping or push) -- this is "has GitHub
// actually reached us", independent of whether the repo itself is reachable.
$webhookStatus = 'unknown';
$webhookLabel = 'Not tracked yet';
try {
    $row = $db->query("SELECT value FROM app_settings WHERE `key` = 'last_webhook_received_at'")->fetch();
    if ($row && !empty($row['value'])) {
        $lastReceived = strtotime($row['value']);
        $daysAgo = ($lastReceived !== false) ? (time() - $lastReceived) / 86400 : null;
        if ($daysAgo !== null) {
            if ($daysAgo <= 2) {
                $webhookStatus = 'ok';
                $webhookLabel = 'Active (' . pw_fmt_ago($lastReceived) . ')';
            } elseif ($daysAgo <= 7) {
                $webhookStatus = 'warn';
                $webhookLabel = 'Quiet (' . pw_fmt_ago($lastReceived) . ')';
            } else {
                $webhookStatus = 'bad';
                $webhookLabel = 'Stale (' . pw_fmt_ago($lastReceived) . ')';
            }
        }
    }
} catch (Exception $e) {
    // app_settings table not migrated yet -- leave as "Not tracked yet".
}

// --- Language sync (backs the Development Snapshot language bar) -----------------
const PW_LANG_SNAPSHOT_TTL = 86400;
$lastSyncLabel = 'No snapshot yet';
$nextSyncLabel = 'Pending first sync';
try {
    $langRow = $db->query('SELECT captured_at FROM repo_language_snapshots ORDER BY captured_at DESC LIMIT 1')->fetch();
    if ($langRow) {
        $capturedTs = strtotime($langRow['captured_at']);
        $lastSyncLabel = pw_fmt_ago($capturedTs) . ' ago';
        $nextSyncTs = $capturedTs + PW_LANG_SNAPSHOT_TTL;
        $nextSyncLabel = ($nextSyncTs <= time()) ? 'Due now' : ('in ' . pw_fmt_ago($nextSyncTs, true));
    }
} catch (Exception $e) {
    // leave defaults
}

// --- Database Load + Size ----------------------------------------------------------
$dbLoad = pw_check_database_load($db);
$dbSize = pw_check_database_size($db);
$mail = pw_check_mail_transport();

// --- Database: connections, throughput, cache efficiency, table sizes -----------
$dbExtra = pw_check_database_extra($db);
$sqlPerformance = pw_check_sql_performance($db);

// --- CPU (shared host) -----------------------------------------------------------
$cpuLoad = pw_check_cpu_load();

// --- SSL certificate + Avatar storage + Total storage ----------------------------
$ssl = pw_check_ssl_expiry();
$spacy = pw_dispatch_spacy_status();
$avatarStorage = pw_check_avatar_storage();
$totalStorage = pw_check_total_storage();

pw_json([
    'ok' => true,
    'github' => ['status' => $githubStatus, 'label' => $githubLabel],
    'webhook' => ['status' => $webhookStatus, 'label' => $webhookLabel],
    'rate_limit' => ['status' => $rateLimitStatus, 'label' => $rateLimitLabel],
    'last_sync' => ['label' => $lastSyncLabel],
    'next_sync' => ['label' => $nextSyncLabel],
    'ssl' => ['status' => $ssl['status'], 'label' => $ssl['label']],
    'spacy' => $spacy,
    'mail' => $mail,
    'db_load' => $dbLoad,
    'database_size' => $dbSize,
    'db_connections' => $dbExtra['connections'],
    'db_qps' => $dbExtra['qps'],
    'db_slow_queries' => $dbExtra['slow_queries'],
    'db_uptime' => $dbExtra['uptime'],
    'db_buffer_pool_hit_ratio' => $dbExtra['buffer_pool_hit_ratio'],
    'db_threads_running' => $dbExtra['threads_running'],
    'db_tables' => $dbExtra['tables'],
    'sql_performance' => $sqlPerformance,
    'cpu_load' => $cpuLoad,
    'avatar_storage' => $avatarStorage,
    'total_storage' => $totalStorage,
]);
