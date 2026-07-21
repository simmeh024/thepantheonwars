<?php
/**
 * TEMPORARY diagnostic script -- not part of the feature, delete after use.
 * Runs one real translation from the database through the embedding worker
 * exactly the way api/dispatch-embeddings.php does, but prints the raw
 * stdout/stderr/exit status instead of swallowing them, so a real failure
 * reason is visible instead of just a pass/fail count.
 *
 * Usage: php tools/debug-dispatch-embedding.php
 */
require_once dirname(__DIR__) . '/api/db.php';

if (!defined('DISPATCH_EMBEDDING_PYTHON_BIN')) {
    fwrite(STDERR, "DISPATCH_EMBEDDING_PYTHON_BIN is not defined.\n");
    exit(1);
}

$python = trim((string)DISPATCH_EMBEDDING_PYTHON_BIN);
$script = dirname(__DIR__) . '/tools/dispatch-embeddings.py';

echo "Python bin: {$python}\n";
echo "  is_file: " . (is_file($python) ? 'yes' : 'NO') . "\n";
echo "  is_executable (is_executable()): " . (is_executable($python) ? 'yes' : 'NO') . "\n";
echo "Script: {$script}\n";
echo "  is_file: " . (is_file($script) ? 'yes' : 'NO') . "\n\n";

$db = pw_db();
// Deliberately pick a row that does NOT already have a cached embedding --
// picking any already-cached row (e.g. plain ORDER BY dispatch_id ASC LIMIT 1)
// would skip the exact code path the failing rows actually go through.
$row = $db->query(
    'SELECT dt.dispatch_id, dt.translation
     FROM dispatch_translations dt
     LEFT JOIN dispatch_translation_embeddings dte ON dte.dispatch_id = dt.dispatch_id
     WHERE dte.dispatch_id IS NULL
     ORDER BY dt.dispatch_id ASC
     LIMIT 1'
)->fetch();
if (!$row) {
    fwrite(STDERR, "No dispatch_translations rows found.\n");
    exit(1);
}
$text = mb_substr(trim((string)$row['translation']), 0, 4000, 'UTF-8');
echo "Testing with dispatch_id={$row['dispatch_id']}, text length=" . strlen($text) . "\n\n";

$payload = ['text' => $text];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
if ($json === false) {
    echo "json_encode FAILED: " . json_last_error_msg() . "\n";
    exit(1);
}
echo "Payload JSON length: " . strlen($json) . "\n\n";

$pipes = [];
$process = @proc_open([$python, $script], [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, null, null, ['bypass_shell' => true]);

if (!is_resource($process)) {
    echo "proc_open FAILED to return a resource.\n";
    $lastError = error_get_last();
    echo "Last PHP error: " . print_r($lastError, true) . "\n";
    exit(1);
}

fwrite($pipes[0], $json);
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$output = '';
$errors = '';
$deadline = microtime(true) + 20.0;
$elapsedLogged = false;
do {
    $output .= stream_get_contents($pipes[1]);
    $errors .= stream_get_contents($pipes[2]);
    $status = proc_get_status($process);
    if (!$status['running']) {
        break;
    }
    usleep(50000);
} while (microtime(true) < $deadline);

$timedOut = $status['running'];
if ($timedOut) {
    proc_terminate($process);
}
$output .= stream_get_contents($pipes[1]);
$errors .= stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

echo "Timed out (20s budget): " . ($timedOut ? 'YES' : 'no') . "\n";
echo "Exit code: {$exitCode}\n\n";

$decoded = json_decode($output, true);
if (!is_array($decoded) || empty($decoded['ok']) || !is_array($decoded['embedding'] ?? null)) {
    echo "Decoded response missing ok/embedding -- stopping before DB write attempt.\n";
    echo "----- STDOUT -----\n" . ($output === '' ? "(empty)\n" : $output . "\n");
    echo "----- STDERR -----\n" . ($errors === '' ? "(empty)\n" : $errors . "\n");
    exit(1);
}
echo "Encode succeeded, embedding length: " . count($decoded['embedding']) . "\n\n";

echo "----- Attempting the real DB write (INSERT ... ON DUPLICATE KEY UPDATE) -----\n";
try {
    $checkTable = $db->query("SHOW TABLES LIKE 'dispatch_translation_embeddings'")->fetchAll();
    echo "Table exists: " . (count($checkTable) > 0 ? 'yes' : 'NO') . "\n";
    if (count($checkTable) > 0) {
        $columns = $db->query('DESCRIBE dispatch_translation_embeddings')->fetchAll();
        foreach ($columns as $col) {
            echo "  column: {$col['Field']} {$col['Type']} null={$col['Null']} key={$col['Key']}\n";
        }
    }

    $hash = hash('sha256', $text);
    $embeddingJson = json_encode($decoded['embedding']);
    echo "embedding_json length: " . strlen((string)$embeddingJson) . "\n";
    echo "model value: '" . (string)($decoded['model'] ?? '') . "'\n";

    $stmt = $db->prepare(
        'INSERT INTO dispatch_translation_embeddings (dispatch_id, model, translation_hash, embedding_json)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           model = VALUES(model),
           translation_hash = VALUES(translation_hash),
           embedding_json = VALUES(embedding_json)'
    );
    $stmt->execute([(int)$row['dispatch_id'], (string)($decoded['model'] ?? ''), $hash, $embeddingJson]);
    echo "INSERT/UPDATE succeeded, rows affected: " . $stmt->rowCount() . "\n";
} catch (PDOException $e) {
    echo "PDOException: " . $e->getMessage() . "\n";
    echo "SQLSTATE: " . implode(',', $e->errorInfo ?? []) . "\n";
}
