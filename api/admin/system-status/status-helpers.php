<?php
/**
 * Shared diagnostics for the System Status card (Home) and the expanded
 * System Status page: avatar storage usage and SSL certificate expiry.
 *
 * (A PHP error log locate/tail/parse pipeline used to live here too, feeding
 * a "Site Errors" check. Removed after confirming live on this host that
 * ~/logs/ only contains access logs -- the account has no PHP-readable error
 * log, and cPanel's own Errors page only works because cPanel's backend
 * reads Apache's error log with elevated privileges a plain PHP script
 * running as this account doesn't have. See git history for that
 * investigation if a future host makes this feasible again.)
 */

// --- The six "System Status" card signals -----------------------------------------
// Extracted out of system-status/summary.php so api/admin/task-advisor.php can
// reuse the exact same checks for its critical-alert detection instead of
// re-implementing (and potentially drifting from) this logic. See
// system-status/summary.php's own doc comment for what each signal means;
// this function's body is verbatim what used to live inline there.
function pw_build_system_signals($db) {
    // --- GitHub Repository ---------------------------------------------------
    $githubStatus = 'bad';
    $githubLabel = 'Unreachable';
    $latestGithubSha = null;

    $ch = curl_init('https://api.github.com/repos/simmeh024/thepantheonwars/commits/main');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => pw_github_curl_headers(),
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

    // --- Database -------------------------------------------------------------
    $dbStatus = 'ok';
    $dbLabel = 'Healthy';
    try {
        $db->query('SELECT 1');
    } catch (Exception $e) {
        $dbStatus = 'bad';
        $dbLabel = 'Unreachable';
    }

    // --- Database Load ----------------------------------------------------------
    $dbLoad = pw_check_database_load($db);

    // --- Forum ------------------------------------------------------------------
    $forumStatus = 'ok';
    $forumLabel = 'Online';
    try {
        $db->query('SELECT COUNT(*) FROM topics');
    } catch (Exception $e) {
        $forumStatus = 'bad';
        $forumLabel = 'Offline';
    }

    // --- Dispatch Sync ------------------------------------------------------------
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

    // --- Avatar Storage -------------------------------------------------------------
    $avatarStorage = pw_check_avatar_storage();

    return [
        'github' => ['status' => $githubStatus, 'label' => $githubLabel],
        'database' => ['status' => $dbStatus, 'label' => $dbLabel],
        'db_load' => $dbLoad,
        'forum' => ['status' => $forumStatus, 'label' => $forumLabel],
        'dispatch_sync' => ['status' => $dispatchSyncStatus, 'label' => $dispatchSyncLabel],
        'avatar_storage' => $avatarStorage,
    ];
}

// --- Small relative-time formatter ------------------------------------------------
// Used by detail.php for "last sync X ago" / "next sync in X" style labels.
// $future=true measures from now() forward to $timestamp instead of back from it.
function pw_fmt_ago($timestamp, $future = false) {
    $diff = $future ? ($timestamp - time()) : (time() - $timestamp);
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60) {
        return $diff . 's';
    }
    $minutes = (int)round($diff / 60);
    if ($minutes < 60) {
        return $minutes . 'm';
    }
    $hours = (int)round($diff / 3600);
    if ($hours < 24) {
        return $hours . 'h';
    }
    $days = (int)round($diff / 86400);
    return $days . 'd';
}

// --- Avatar storage ------------------------------------------------------------
// Members' avatars live in /uploads/avatars as flat jpg files (one per user id).
// There's no real disk quota tied to this folder -- 5 GiB is a soft budget we
// picked so the bar has a meaningful "full" point long before the account's
// actual hosting quota would ever be a concern.
function pw_check_avatar_storage() {
    $dir = __DIR__ . '/../../../uploads/avatars';
    $maxBytes = 5 * 1024 * 1024 * 1024; // 5 GiB soft budget
    $used = 0;
    if (is_dir($dir)) {
        foreach (new DirectoryIterator($dir) as $f) {
            if ($f->isFile()) {
                $used += $f->getSize();
            }
        }
    }
    $pct = $maxBytes > 0 ? min(100, ($used / $maxBytes) * 100) : 0;
    $usedGb = $used / (1024 * 1024 * 1024);
    $status = 'ok';
    if ($usedGb >= 4.5) {
        $status = 'bad';
    } elseif ($usedGb >= 4.0) {
        $status = 'warn';
    }
    return [
        'used_bytes' => $used,
        'max_bytes' => $maxBytes,
        'used_mb' => round($used / (1024 * 1024), 2),
        'max_mb' => round($maxBytes / (1024 * 1024)),
        'pct' => round($pct, 1),
        'status' => $status,
    ];
}

// --- Database load (query latency) ----------------------------------------------
// A rough "how loaded is the DB right now" signal for shared hosting, where
// there's no access to OS-level metrics or SHOW GLOBAL STATUS-style admin
// views: time a real query against a real table (not just SELECT 1, which
// only measures connection overhead) and read the elapsed time as a proxy
// for current load/contention. Thresholds are a starting point, not a
// measured baseline -- tune them if they don't match how this host behaves
// in practice.
function pw_check_database_load($db) {
    $start = microtime(true);
    try {
        $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Exception $e) {
        return ['status' => 'bad', 'label' => 'Unreachable', 'ms' => null];
    }
    $elapsedMs = (microtime(true) - $start) * 1000;
    $status = 'ok';
    if ($elapsedMs >= 150) {
        $status = 'bad';
    } elseif ($elapsedMs >= 50) {
        $status = 'warn';
    }
    return [
        'status' => $status,
        'label' => round($elapsedMs, 1) . ' ms',
        'ms' => round($elapsedMs, 1),
    ];
}

// --- Database size ---------------------------------------------------------------
// Total on-disk size (data + indexes) of every table in this schema, via
// information_schema -- the only way to get storage usage without OS-level
// access on shared hosting. cPanel's MySQL Databases page shows a live
// "Size" column per database but no quota (confirmed live: this account's
// databases list has no quota figure anywhere), so -- same as avatar
// storage -- this is a soft budget we picked, not a real host limit. Tune
// PW_DB_SIZE_BUDGET_BYTES if it doesn't match how this host actually caps
// things.
function pw_check_database_size($db) {
    $maxBytes = 500 * 1024 * 1024; // 500 MiB soft budget
    $usedBytes = 0;
    try {
        $row = $db->query(
            'SELECT SUM(data_length + index_length) AS bytes FROM information_schema.TABLES WHERE table_schema = DATABASE()'
        )->fetch();
        if ($row && $row['bytes'] !== null) {
            $usedBytes = (float)$row['bytes'];
        }
    } catch (Exception $e) {
        return [
            'used_bytes' => 0,
            'max_bytes' => $maxBytes,
            'used_mb' => 0,
            'max_mb' => round($maxBytes / (1024 * 1024)),
            'pct' => 0,
            'status' => 'unknown',
        ];
    }
    $pct = $maxBytes > 0 ? min(100, ($usedBytes / $maxBytes) * 100) : 0;
    $status = 'ok';
    if ($pct >= 90) {
        $status = 'bad';
    } elseif ($pct >= 80) {
        $status = 'warn';
    }
    return [
        'used_bytes' => $usedBytes,
        'max_bytes' => $maxBytes,
        'used_mb' => round($usedBytes / (1024 * 1024), 2),
        'max_mb' => round($maxBytes / (1024 * 1024)),
        'pct' => round($pct, 1),
        'status' => $status,
    ];
}

// --- Total account storage --------------------------------------------------------
// Whole-account disk usage (not just the avatars folder or the database).
// disk_free_space()/disk_total_space() were tried first but turned out to
// reflect the underlying shared partition (effectively unlimited), not this
// account's actual quota -- confirmed live: they always computed ~0 MB used
// against a 24 GiB budget, while cPanel's own Disk Usage page showed the
// real figure (a few hundred MB). `du -sb` against the home directory is
// what actually agrees with cPanel's own numbers, so that's what's used
// here (shell_exec is available on this host -- confirmed during the CPU/DB
// introspection sweep). PW budget constants below match the real hosting
// plan size (24 GiB); update them if the plan ever changes.
function pw_check_total_storage() {
    $homeDir = '/home/rdy3i6my40b0';
    $maxBytes = 24 * 1024 * 1024 * 1024; // 24 GiB plan budget
    $warnBytes = 20 * 1024 * 1024 * 1024; // warn once used crosses 20 GiB
    $badBytes = 22 * 1024 * 1024 * 1024; // bad once used crosses 22 GiB (~92%)

    $usedBytes = null;
    if (function_exists('shell_exec')) {
        $output = @shell_exec('du -sb ' . escapeshellarg($homeDir) . ' 2>/dev/null');
        if ($output !== null && preg_match('/^(\d+)/', trim($output), $m)) {
            $usedBytes = (float)$m[1];
        }
    }

    if ($usedBytes === null) {
        return [
            'used_bytes' => 0,
            'max_bytes' => $maxBytes,
            'used_mb' => 0,
            'max_mb' => round($maxBytes / (1024 * 1024)),
            'pct' => 0,
            'status' => 'unknown',
        ];
    }

    $pct = $maxBytes > 0 ? min(100, ($usedBytes / $maxBytes) * 100) : 0;
    $status = 'ok';
    if ($usedBytes >= $badBytes) {
        $status = 'bad';
    } elseif ($usedBytes >= $warnBytes) {
        $status = 'warn';
    }
    return [
        'used_bytes' => $usedBytes,
        'max_bytes' => $maxBytes,
        // Matches the used_mb/max_mb shape pw_check_avatar_storage() and
        // pw_check_database_size() already return -- the frontend's
        // setAvatarStorageBar() renderer is shared across all three and
        // expects *_mb specifically, not *_gb.
        'used_mb' => round($usedBytes / (1024 * 1024), 2),
        'max_mb' => round($maxBytes / (1024 * 1024)),
        'pct' => round($pct, 1),
        'status' => $status,
    ];
}

// --- SSL certificate expiry ------------------------------------------------------
function pw_check_ssl_expiry($host = 'thepantheonwars.com') {
    $result = ['expires_at' => null, 'days_left' => null, 'status' => 'unknown', 'label' => 'Unknown'];
    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $client = @stream_socket_client(
        'ssl://' . $host . ':443',
        $errno,
        $errstr,
        5,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!$client) {
        return $result;
    }
    $params = stream_context_get_params($client);
    fclose($client);
    if (empty($params['options']['ssl']['peer_certificate'])) {
        return $result;
    }
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    if (!$cert || empty($cert['validTo_time_t'])) {
        return $result;
    }
    $expiresAt = $cert['validTo_time_t'];
    $daysLeft = (int)floor(($expiresAt - time()) / 86400);
    $status = 'ok';
    if ($daysLeft <= 14) {
        $status = 'bad';
    } elseif ($daysLeft <= 28) {
        $status = 'warn';
    }
    return [
        'expires_at' => gmdate('Y-m-d', $expiresAt),
        'days_left' => $daysLeft,
        'status' => $status,
        'label' => $daysLeft . ($daysLeft === 1 ? ' day left' : ' days left'),
    ];
}

// --- CPU load (shared host) ------------------------------------------------------
// sys_getloadavg() reports the load average for the ENTIRE shared box, not
// this account in isolation -- there's no per-account CPU metric available
// on shared hosting. Still a useful "is something hammering the server"
// signal (including this site's own traffic), which is what backs the
// CPU (Shared) card's live numbers and its 24h history chart (samples are
// collected once a minute by a cron job into cpu_load_history -- see
// api/cron/sample-load.php -- since a single request can only ever see a
// single instant, not a trend, and a DDoS-style spike needs the trend to
// actually be visible).
function pw_check_cpu_load() {
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    if (!$load) {
        return [
            'status' => 'unknown',
            'label' => 'Unavailable',
            'load1' => null,
            'load5' => null,
            'load15' => null,
            'cores' => null,
        ];
    }
    $cores = 1;
    if (is_readable('/proc/cpuinfo')) {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo !== false) {
            $cores = max(1, preg_match_all('/^processor\s*:/m', $cpuinfo));
        }
    }
    $ratio = $load[0] / $cores;
    $status = 'ok';
    if ($ratio >= 1.0) {
        $status = 'bad';
    } elseif ($ratio >= 0.7) {
        $status = 'warn';
    }
    return [
        'status' => $status,
        'label' => round($load[0], 2) . ' / ' . round($load[1], 2) . ' / ' . round($load[2], 2),
        'load1' => round($load[0], 2),
        'load5' => round($load[1], 2),
        'load15' => round($load[2], 2),
        'cores' => $cores,
    ];
}

// --- Database: connections, throughput, cache efficiency, table sizes -----------
// Deeper MySQL introspection than pw_check_database_load()'s single-query
// timing proxy above -- these come straight from SHOW GLOBAL STATUS/
// SHOW GLOBAL VARIABLES, which (confirmed empirically on this host via a
// temporary diagnostic endpoint, see git history) this account's DB user
// can read without any elevated privilege. SHOW ENGINE INNODB STATUS and a
// cross-connection SHOW PROCESSLIST are NOT available here (missing
// PROCESS privilege / scoped to only this app's own connection) so neither
// is attempted.
function pw_status_global($db, $name) {
    try {
        $row = $db->query('SHOW GLOBAL STATUS LIKE ' . $db->quote($name))->fetch();
        return $row ? $row['Value'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function pw_status_variable($db, $name) {
    try {
        $row = $db->query('SHOW GLOBAL VARIABLES LIKE ' . $db->quote($name))->fetch();
        return $row ? $row['Value'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function pw_check_database_extra($db) {
    $result = [
        'connections' => ['status' => 'unknown', 'label' => 'Unavailable'],
        'qps' => ['status' => 'unknown', 'label' => 'Unavailable'],
        'slow_queries' => ['status' => 'unknown', 'label' => 'Unavailable'],
        'uptime' => ['label' => 'Unavailable'],
        'buffer_pool_hit_ratio' => ['status' => 'unknown', 'label' => 'Unavailable'],
        'threads_running' => ['status' => 'unknown', 'label' => 'Unavailable'],
        'tables' => [],
    ];

    // Connections vs max_connections
    $connected = pw_status_global($db, 'Threads_connected');
    $maxConn = pw_status_variable($db, 'max_connections');
    if ($connected !== null && $maxConn !== null && (int)$maxConn > 0) {
        $pct = ((int)$connected / (int)$maxConn) * 100;
        $status = 'ok';
        if ($pct >= 90) {
            $status = 'bad';
        } elseif ($pct >= 70) {
            $status = 'warn';
        }
        $result['connections'] = [
            'status' => $status,
            'label' => $connected . ' / ' . $maxConn . ' (' . round($pct, 1) . '%)',
        ];
    }

    // Queries/sec, averaged since server start (a single request can't see a
    // real-time rate without a second sample to diff against, so this is
    // explicitly labeled "(avg)" rather than implying a live figure).
    $questions = pw_status_global($db, 'Questions');
    $uptime = pw_status_global($db, 'Uptime');
    if ($questions !== null && $uptime !== null && (int)$uptime > 0) {
        $qps = (int)$questions / (int)$uptime;
        $result['qps'] = ['status' => 'ok', 'label' => round($qps, 2) . ' / sec (avg)'];
    }

    // Slow queries: cumulative count since server start (per the
    // long_query_time threshold configured on this host).
    $slowQueries = pw_status_global($db, 'Slow_queries');
    if ($slowQueries !== null) {
        $slow = (int)$slowQueries;
        $status = 'ok';
        if ($slow >= 200) {
            $status = 'bad';
        } elseif ($slow >= 50) {
            $status = 'warn';
        }
        $result['slow_queries'] = ['status' => $status, 'label' => $slow . ' total'];
    }

    // Server uptime (reuses the Uptime status var already fetched above for QPS).
    if ($uptime !== null) {
        $result['uptime'] = ['label' => pw_fmt_duration((int)$uptime)];
    }

    // InnoDB buffer pool hit ratio -- how often reads are served from memory
    // instead of falling through to disk.
    $readRequests = pw_status_global($db, 'Innodb_buffer_pool_read_requests');
    $reads = pw_status_global($db, 'Innodb_buffer_pool_reads');
    if ($readRequests !== null && $reads !== null && (int)$readRequests > 0) {
        $ratio = (1 - ((int)$reads / (int)$readRequests)) * 100;
        $status = 'ok';
        if ($ratio < 95) {
            $status = 'bad';
        } elseif ($ratio < 99) {
            $status = 'warn';
        }
        $result['buffer_pool_hit_ratio'] = ['status' => $status, 'label' => round($ratio, 2) . '%'];
    }

    // Threads currently running (actively executing a query right now, not
    // just connected-and-idle) -- a sudden spike here is a much more
    // immediate load signal than Threads_connected.
    $threadsRunning = pw_status_global($db, 'Threads_running');
    if ($threadsRunning !== null) {
        $running = (int)$threadsRunning;
        $status = 'ok';
        if ($running >= 20) {
            $status = 'bad';
        } elseif ($running >= 10) {
            $status = 'warn';
        }
        $result['threads_running'] = ['status' => $status, 'label' => (string)$running];
    }

    // Per-table size breakdown (largest first), flagging any table whose
    // collation isn't utf8mb4_unicode_ci -- this is exactly how the books
    // table's stray latin1_swedish_ci collation was originally found, so
    // surfacing it here means a future mismatch like that won't go
    // unnoticed again.
    try {
        $rows = $db->query(
            'SELECT table_name, table_collation, table_rows,
                    (data_length + index_length) AS size_bytes
             FROM information_schema.TABLES
             WHERE table_schema = DATABASE()
             ORDER BY size_bytes DESC
             LIMIT 10'
        )->fetchAll();
        foreach ($rows as $row) {
            $result['tables'][] = [
                'name' => $row['table_name'],
                'size_mb' => round(((float)$row['size_bytes']) / (1024 * 1024), 2),
                'rows' => (int)$row['table_rows'],
                'collation' => $row['table_collation'],
                'collation_mismatch' => ($row['table_collation'] !== 'utf8mb4_unicode_ci'),
            ];
        }
    } catch (Exception $e) {
        // leave tables empty
    }

    return $result;
}

// --- Last backup (manually logged) ------------------------------------------------
// cPanel's automated account backups are disabled on this hosting account
// (confirmed live via the Backup page: "Your server administrator or
// server owner must enable this feature") so there's no real automated
// timestamp anywhere to check. This reads the most recent row a human
// logged via "Log Backup Now" (api/admin/system-status/log-backup.php)
// instead -- imperfect (self-reported, not verified), but real data
// rather than a fabricated status.
function pw_check_last_backup($db) {
    try {
        $row = $db->query('SELECT created_at FROM backup_log ORDER BY created_at DESC LIMIT 1')->fetch();
    } catch (Exception $e) {
        return ['status' => 'unknown', 'label' => 'Unavailable', 'logged_at' => null];
    }
    if (!$row) {
        return ['status' => 'bad', 'label' => 'Never logged', 'logged_at' => null];
    }

    $loggedAt = strtotime($row['created_at'] . ' UTC');
    $diff = time() - $loggedAt;
    $days = intdiv($diff, 86400);
    $hours = intdiv($diff % 86400, 3600);

    if ($days > 0) {
        $label = $days . ($days === 1 ? ' day' : ' days');
        if ($hours > 0) {
            $label .= ' and ' . $hours . ($hours === 1 ? ' hour' : ' hours');
        }
        $label .= ' ago';
    } elseif ($hours > 0) {
        $label = $hours . ($hours === 1 ? ' hour' : ' hours') . ' ago';
    } else {
        $minutes = max(1, intdiv($diff, 60));
        $label = $minutes . ($minutes === 1 ? ' minute' : ' minutes') . ' ago';
    }

    $status = 'ok';
    if ($days >= 14) {
        $status = 'bad';
    } elseif ($days >= 7) {
        $status = 'warn';
    }

    return ['status' => $status, 'label' => $label, 'logged_at' => $row['created_at']];
}

// --- Small duration formatter (seconds -> "12d 4h" / "3h 20m" / "45m" style) -----
function pw_fmt_duration($seconds) {
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    return $minutes . 'm';
}
