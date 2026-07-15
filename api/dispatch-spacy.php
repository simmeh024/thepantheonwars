<?php
/**
 * Optional local spaCy enrichment for Dispatch drafts.
 *
 * This bridge is deliberately fail-closed: it never exposes a public endpoint,
 * never sends commit text away from the hosting account, and returns an empty
 * analysis if Python, spaCy, or its model is unavailable. The rule-based
 * formatter then produces exactly its established fallback result.
 */

function pw_dispatch_spacy_reader_object(array $analysis): string
{
    $candidates = array_merge(
        is_array($analysis['entities'] ?? null) ? $analysis['entities'] : [],
        is_array($analysis['phrases'] ?? null) ? $analysis['phrases'] : []
    );
    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }
        $candidate = trim(preg_replace('/\s+/', ' ', $candidate));
        if (strlen($candidate) >= 3 && strlen($candidate) <= 100) {
            return $candidate;
        }
    }
    return '';
}

function pw_dispatch_spacy_analyze(string $subject, string $body): array
{
    static $unavailable = false;
    if ($unavailable || !function_exists('proc_open') || !defined('SPACY_PYTHON_BIN')) {
        return [];
    }

    $python = trim((string)SPACY_PYTHON_BIN);
    $script = dirname(__DIR__) . '/tools/dispatch-nlp.py';
    if ($python === '' || !is_file($python) || !is_file($script)) {
        $unavailable = true;
        return [];
    }

    $truncate = static function (string $value, int $length): string {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    };
    $payload = json_encode(
        ($subject === '' && $body === '')
            ? ['health' => true]
            : [
                'subject' => $truncate($subject, 4000),
                'body' => $truncate($body, 8000),
            ],
        JSON_UNESCAPED_UNICODE
    );
    if ($payload === false) {
        return [];
    }

    $environment = null;
    if (defined('SPACY_MODEL') && trim((string)SPACY_MODEL) !== '') {
        $environment = ['PW_SPACY_MODEL' => trim((string)SPACY_MODEL)];
    }
    $pipes = [];
    $process = @proc_open([$python, $script], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        $unavailable = true;
        return [];
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $errors = '';
    $deadline = microtime(true) + 1.5;
    do {
        $output .= stream_get_contents($pipes[1]);
        $errors .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    if ($status['running']) {
        proc_terminate($process);
        $unavailable = true;
    }
    $output .= stream_get_contents($pipes[1]);
    $errors .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $analysis = json_decode($output, true);
    if (!is_array($analysis) || empty($analysis['ok'])) {
        if ($errors !== '') {
            $unavailable = true;
        }
        return [];
    }
    return [
        'actions' => is_array($analysis['actions'] ?? null) ? $analysis['actions'] : [],
        'phrases' => is_array($analysis['phrases'] ?? null) ? $analysis['phrases'] : [],
        'entities' => is_array($analysis['entities'] ?? null) ? $analysis['entities'] : [],
    ];
}

/**
 * The System Status page uses a real model-load check rather than merely
 * checking whether a secrets constant exists. This catches a removed venv,
 * a missing model, disabled proc_open, and an unresponsive worker alike.
 */
function pw_dispatch_spacy_status(): array
{
    $analysis = pw_dispatch_spacy_analyze('', '');
    return $analysis === []
        ? ['status' => 'bad', 'label' => 'Disconnected']
        : ['status' => 'ok', 'label' => 'Connected'];
}
