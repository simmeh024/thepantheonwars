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
$row = $db->query('SELECT dispatch_id, translation FROM dispatch_translations ORDER BY dispatch_id ASC LIMIT 1')->fetch();
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
echo "----- STDOUT -----\n";
echo $output === '' ? "(empty)\n" : $output . "\n";
echo "----- STDERR -----\n";
echo $errors === '' ? "(empty)\n" : $errors . "\n";
