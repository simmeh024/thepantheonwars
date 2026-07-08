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
