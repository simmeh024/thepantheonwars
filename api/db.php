<?php
/**
 * Shared DB bootstrap. Loads credentials from OUTSIDE the web root so they
 * are never in git and never web-accessible. If this file is missing, the
 * member system simply isn't configured yet — fail closed with a clear error.
 */

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
    }
    return $pdo;
}
