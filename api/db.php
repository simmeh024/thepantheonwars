<?php
/**
 * Shared DB bootstrap. Loads credentials from OUTSIDE the web root so they
 * are never in git and never web-accessible. If this file is missing, the
 * member system simply isn't configured yet — fail closed with a clear error.
 *
 * Server-wide UTC: the box's system time_zone turned out to be MST
 * (UTC-7, confirmed live: MySQL's NOW() was running 7 hours behind
 * UTC_TIMESTAMP()), and PHP's date() calls were riding on whatever the
 * server's default zone was too. Every timestamp this app writes --
 * PHP's date('Y-m-d H:i:s') calls and MySQL's NOW()/CURRENT_TIMESTAMP
 * column defaults alike -- needs to land in UTC from here on, so both are
 * pinned centrally in this one shared bootstrap rather than per-endpoint.
 * (dispatch_entries.committed_at is the one exception: it's populated
 * straight from GitHub's commit metadata, not the server's clock, so it's
 * intentionally left alone -- see github-webhook.php / dispatches/resync.php.)
 */

date_default_timezone_set('UTC');

$secretsPath = '/home/rdy3i6my40b0/pantheonwars-secrets/config.php';

if (!file_exists($secretsPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Member system is not configured yet.']);
    exit;
}

require_once $secretsPath;

function pw_db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Pin this connection's session to UTC so NOW()/CURRENT_TIMESTAMP
        // (used all over -- admin_activity_log, content_reports, topics,
        // comments, users.last_active_at, etc.) write UTC instead of the
        // server's local system zone.
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
