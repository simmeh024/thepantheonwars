<?php
/**
 * Shared diagnostics for the System Status card (Home) and the expanded
 * System Status page: avatar storage usage, SSL certificate expiry, and the
 * PHP error log locate/tail/parse pipeline. Kept in one file since both
 * summary.php (compact Home card) and detail.php/errors.php (expanded page)
 * need the same checks.
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
        'used_gb' => round($usedGb, 2),
        'max_gb' => 5,
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

// --- PHP error log ----------------------------------------------------------------
// The exact log path varies by hosting setup, so we ask PHP itself (via
// ini_get) what it's configured to write to, and fall back to a couple of
// common cPanel locations if that's empty. If none of these are readable we
// report that plainly rather than pretending everything's fine.
function pw_error_log_path() {
    $candidates = [];
    $iniPath = ini_get('error_log');
    if ($iniPath) {
        $candidates[] = $iniPath;
    }
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
        $candidates[] = $docRoot . '/error_log';
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'thepantheonwars.com';
        $candidates[] = dirname($docRoot) . '/logs/' . $host;
        $candidates[] = dirname($docRoot) . '/logs/' . preg_replace('/^www\./', '', $host);
    }
    foreach ($candidates as $c) {
        if ($c && is_file($c) && is_readable($c)) {
            return $c;
        }
    }
    return null;
}

// Reads at most $maxBytes from the tail of the file and splits into lines.
function pw_tail_lines($path, $maxBytes = 2 * 1024 * 1024) {
    $size = filesize($path);
    if ($size === false || $size === 0) {
        return [];
    }
    $fh = fopen($path, 'r');
    if (!$fh) {
        return [];
    }
    $readBytes = min($size, $maxBytes);
    fseek($fh, -$readBytes, SEEK_END);
    $data = fread($fh, $readBytes);
    fclose($fh);
    if ($data === false) {
        return [];
    }
    return explode("\n", $data);
}

// Each PHP error_log entry starts with "[dd-Mon-yyyy hh:mm:ss TZ] PHP <Level>: ...".
// Continuation lines (stack traces etc.) aren't bracketed -- we drop those and
// keep just the headline message, which is plenty for a status glance.
function pw_parse_error_log_lines($lines) {
    $entries = [];
    foreach ($lines as $line) {
        if (!preg_match('/^\[(.*?)\]\s*(.*)$/', $line, $m)) {
            continue;
        }
        $rawTs = $m[1];
        $msg = trim($m[2]);
        if ($msg === '') {
            continue;
        }
        $ts = strtotime($rawTs);
        $critical = (bool)preg_match('/Fatal error|Parse error|Uncaught|Compile Error|Core Error/i', $msg);
        $location = null;
        if (preg_match('/ in (.+?) on line (\d+)/', $msg, $fm)) {
            $location = basename($fm[1]) . ':' . $fm[2];
        }
        $entries[] = [
            'timestamp' => $ts ? gmdate('Y-m-d H:i:s', $ts) : $rawTs,
            'critical' => $critical,
            'message' => preg_replace('/^PHP\s+/i', '', $msg),
            'location' => $location,
        ];
    }
    // File is chronological; newest entries should come first for display.
    return array_reverse($entries);
}

function pw_load_error_entries() {
    $path = pw_error_log_path();
    if ($path === null) {
        return ['available' => false, 'entries' => []];
    }
    $lines = pw_tail_lines($path);
    $entries = pw_parse_error_log_lines($lines);
    return ['available' => true, 'entries' => $entries];
}

function pw_count_recent_critical($entries, $hours = 24) {
    $cutoff = time() - ($hours * 3600);
    $count = 0;
    foreach ($entries as $e) {
        $ts = strtotime($e['timestamp']);
        if ($e['critical'] && $ts !== false && $ts >= $cutoff) {
            $count++;
        }
    }
    return $count;
}
