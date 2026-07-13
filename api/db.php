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

// Lightweight application-level SQL diagnostics. Shared hosting does not expose
// a usable slow-query log, so statements exceeding the threshold are recorded
// here without ever storing bound values. The guard also makes the diagnostic
// insert itself invisible to the monitor.
class PW_SQL_Statement extends PDOStatement {
    protected function __construct() {}

    public function execute(?array $params = null): bool {
        if (PW_PDO::is_monitoring()) {
            return parent::execute($params);
        }
        $started = hrtime(true);
        $ok = parent::execute($params);
        $durationMs = (hrtime(true) - $started) / 1000000;
        PW_PDO::add_request_metric($durationMs);
        PW_PDO::record_query($this->queryString, $durationMs, $this->rowCount());
        return $ok;
    }
}

class PW_PDO extends PDO {
    private static $monitoring = false;
    private static $thresholdMs = null;
    private static $instance = null;
    private static $requestQueryCount = 0;
    private static $requestDbMs = 0.0;

    public static function set_instance(PW_PDO $pdo): void {
        self::$instance = $pdo;
    }

    public static function is_monitoring(): bool {
        return self::$monitoring;
    }

    public static function add_request_metric(float $durationMs): void {
        if (self::$monitoring) return;
        self::$requestQueryCount++;
        self::$requestDbMs += $durationMs;
    }

    public static function request_metrics(): array {
        return ['queries' => self::$requestQueryCount, 'db_ms' => round(self::$requestDbMs, 3)];
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (self::$monitoring) {
            return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        }
        $started = hrtime(true);
        $result = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        $durationMs = (hrtime(true) - $started) / 1000000;
        self::add_request_metric($durationMs);
        self::record_query($query, $durationMs, $result ? $result->rowCount() : 0);
        return $result;
    }

    public function exec(string $statement): int|false {
        if (self::$monitoring) return parent::exec($statement);
        $started = hrtime(true);
        $rows = parent::exec($statement);
        $durationMs = (hrtime(true) - $started) / 1000000;
        self::add_request_metric($durationMs);
        self::record_query($statement, $durationMs, $rows === false ? 0 : $rows);
        return $rows;
    }

    public static function record_query(string $sql, float $durationMs, int $rows): void {
        if (self::$monitoring || $durationMs < self::threshold_ms()) return;
        self::$monitoring = true;
        try {
            $fingerprint = self::fingerprint($sql);
            if ($fingerprint === '') return;
            $severity = $durationMs >= 2000 ? 'critical' : ($durationMs >= 500 ? 'slow' : ($durationMs >= 250 ? 'warning' : 'info'));
            $path = isset($_SERVER['SCRIPT_NAME']) ? substr((string)$_SERVER['SCRIPT_NAME'], 0, 255) : 'cli';
            $method = isset($_SERVER['REQUEST_METHOD']) ? substr((string)$_SERVER['REQUEST_METHOD'], 0, 10) : 'CLI';
            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $requestId = substr(hash('sha256', (string)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)) . '|' . $path . '|' . random_int(1, PHP_INT_MAX)), 0, 32);
            $category = self::category($path);
            if (!self::$instance) return;
            $stmt = self::$instance->prepare(
                'INSERT INTO sql_performance_logs (query_hash, query_fingerprint, endpoint, request_method, category, execution_ms, rows_affected, severity, user_id, request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([hash('sha256', $fingerprint), $fingerprint, $path, $method, $category, round($durationMs, 3), $rows, $severity, $userId, $requestId]);
        } catch (Throwable $e) {
            // Diagnostics must never make a live request fail, including before
            // its migration has been applied.
        } finally {
            self::$monitoring = false;
        }
    }

    private static function threshold_ms(): float {
        if (self::$thresholdMs !== null) return self::$thresholdMs;
        self::$thresholdMs = 100.0;
        return self::$thresholdMs;
    }

    private static function fingerprint(string $sql): string {
        $sql = preg_replace('/\/\*.*?\*\/|--[^\r\n]*/s', ' ', $sql);
        $sql = preg_replace("/'(?:\\\\.|[^'\\\\])*'/", '?', $sql);
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql);
        $sql = preg_replace('/\s+/', ' ', trim((string)$sql));
        return substr($sql, 0, 2000);
    }

    private static function category(string $path): string {
        foreach (['visitor-stats' => 'Visitor Statistics', 'forum' => 'Forum', 'login' => 'Authentication', 'world' => 'Worlds', 'book' => 'Books', 'message' => 'Messaging', 'dispatch' => 'News', 'admin' => 'Admin Console'] as $needle => $category) {
            if (stripos($path, $needle) !== false) return $category;
        }
        return 'System';
    }
}

function pw_db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PW_PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STATEMENT_CLASS => [PW_SQL_Statement::class],
        ]);
        PW_PDO::set_instance($pdo);
        // Pin this connection's session to UTC so NOW()/CURRENT_TIMESTAMP
        // (used all over -- admin_activity_log, content_reports, topics,
        // comments, users.last_active_at, etc.) write UTC instead of the
        // server's local system zone.
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
