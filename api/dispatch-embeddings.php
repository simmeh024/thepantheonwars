<?php
/**
 * Optional local sentence-embedding bridge for Dispatch Translation semantic
 * similarity, mirroring api/dispatch-spacy.php's proc_open shape exactly --
 * a fresh one-shot Python process per call (tools/dispatch-embeddings.py),
 * not a persistent service.
 *
 * This was originally a persistent Flask/Passenger app talking over HTTP.
 * Live production evidence reversed that: `ps -u ... --sort=-rss` showed the
 * persistent process resident at ~850MB on an account with roughly 1.5GB
 * total RAM, and every live translation that invoked it immediately got the
 * (much lighter) spaCy Passenger worker OOM-killed. See
 * docs/dispatch-embeddings.md for the full account. A one-shot proc_open
 * process pays a few extra seconds of torch-import/model-load latency per
 * call -- the same tradeoff api/dispatch-spacy.php already makes -- but
 * releases every byte of that memory the instant it exits, so nothing sits
 * resident between calls competing with spaCy or anything else on the
 * account.
 *
 * If the interpreter, script, or model is unconfigured, missing, or slow,
 * every function here fails open to an empty result -- the deterministic
 * translator then produces exactly its established fallback, with zero
 * change to confidence, wording, or publication decisions.
 */

/**
 * Shared process helper. Returns the decoded response body (already
 * confirmed to have `ok: true`) or null on any failure. A missing
 * interpreter/script latches $unavailable for the rest of this request,
 * same as api/dispatch-spacy.php's pattern -- there is no point retrying a
 * worker that can't even start.
 */
function pw_dispatch_embedding_request(array $payload): ?array
{
    static $unavailable = false;
    if ($unavailable || !function_exists('proc_open') || !defined('DISPATCH_EMBEDDING_PYTHON_BIN')) {
        return null;
    }

    $python = trim((string)DISPATCH_EMBEDDING_PYTHON_BIN);
    $script = dirname(__DIR__) . '/tools/dispatch-embeddings.py';
    if ($python === '' || !is_file($python) || !is_file($script)) {
        $unavailable = true;
        return null;
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return null;
    }

    $pipes = [];
    $process = @proc_open([$python, $script], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        $unavailable = true;
        return null;
    }

    fwrite($pipes[0], $json);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $errors = '';
    // Cold-starting torch + sentence-transformers on this host commonly
    // costs a few seconds before any encode happens at all. Bounded slightly
    // looser than api/dispatch-spacy.php's 6s since this worker runs far
    // less often (draft generation only, never per-keystroke), but still
    // strict enough that a hung process can never block a request
    // indefinitely.
    $deadline = microtime(true) + 10.0;
    do {
        $output .= stream_get_contents($pipes[1]);
        $errors .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(20000);
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

    $decoded = json_decode($output, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        if ($errors !== '') {
            $unavailable = true;
        }
        return null;
    }
    return $decoded;
}

/**
 * Encode one string. Only ever called with the current incoming commit's
 * cleaned text, or (at publish/edit time) one just-approved translation's
 * text -- never a batch of prior translations. The cached corpus itself
 * lives in dispatch_translation_embeddings, computed by PHP from these
 * single-string results, never sent back to this worker as a whole.
 */
function pw_dispatch_embedding_similarity(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $decoded = pw_dispatch_embedding_request(['text' => mb_substr($text, 0, 4000, 'UTF-8')]);
    if ($decoded === null || !is_array($decoded['embedding'] ?? null)) {
        return [];
    }
    return [
        'embedding' => array_map('floatval', $decoded['embedding']),
        'model' => isset($decoded['model']) ? (string)$decoded['model'] : '',
    ];
}

/**
 * System Status uses a real process run rather than merely checking whether
 * the config constant exists, matching pw_dispatch_spacy_status()'s
 * reasoning -- this catches a missing venv, a removed model, disabled
 * proc_open, and a hung model load alike. Deliberately not wired into BH-4's
 * critical-directive escalation the way spaCy is: this signal is additive
 * and optional, never load-bearing for publication, so a disconnected
 * worker is a System Status row, not an admin alert.
 */
function pw_dispatch_embedding_status(): array
{
    $decoded = pw_dispatch_embedding_request(['health' => true]);
    return $decoded === null
        ? ['status' => 'bad', 'label' => 'Disconnected']
        : ['status' => 'ok', 'label' => 'Connected'];
}

/**
 * Cosine similarity between two equal-length float vectors. The embedding
 * service already returns unit-normalized vectors, so this is effectively a
 * dot product in practice -- the full formula is kept so a future model
 * change (or a stored vector from before normalization was added) can never
 * silently produce a meaningless score.
 */
function pw_dispatch_cosine_similarity(array $a, array $b): float
{
    $count = min(count($a), count($b));
    if ($count === 0) {
        return 0.0;
    }
    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    for ($i = 0; $i < $count; $i++) {
        $dot += (float)$a[$i] * (float)$b[$i];
        $normA += (float)$a[$i] * (float)$a[$i];
        $normB += (float)$b[$i] * (float)$b[$i];
    }
    if ($normA <= 0.0 || $normB <= 0.0) {
        return 0.0;
    }
    return $dot / (sqrt($normA) * sqrt($normB));
}

/**
 * Look up the single best-matching approved translation for a query vector,
 * computed entirely in PHP against the cached corpus -- no network round
 * trip per comparison, only the one earlier /encode call that produced
 * $queryVector. Returns [] below the match threshold rather than the
 * merely-closest row: a low score is more likely coincidental topical
 * overlap than a genuinely similar past Dispatch worth showing an editor.
 */
function pw_dispatch_nearest_embedding_match(PDO $db, array $queryVector, int $excludeDispatchId): array
{
    if ($queryVector === []) {
        return [];
    }
    try {
        $stmt = $db->prepare(
            'SELECT dte.dispatch_id, dte.embedding_json, dt.translation, de.subject
             FROM dispatch_translation_embeddings dte
             INNER JOIN dispatch_translations dt ON dt.dispatch_id = dte.dispatch_id
             INNER JOIN dispatch_entries de ON de.id = dte.dispatch_id
             WHERE dte.dispatch_id <> ?'
        );
        $stmt->execute([$excludeDispatchId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        // The optional migration may not be applied yet.
        return [];
    }

    $best = null;
    $bestScore = -1.0;
    foreach ($rows as $row) {
        $vector = json_decode((string)$row['embedding_json'], true);
        if (!is_array($vector)) {
            continue;
        }
        $score = pw_dispatch_cosine_similarity($queryVector, $vector);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $row;
        }
    }
    if ($best === null || $bestScore < 0.75) {
        return [];
    }
    return [
        'dispatch_id' => (int)$best['dispatch_id'],
        'score' => round($bestScore, 4),
        'subject' => (string)$best['subject'],
        'translation' => (string)$best['translation'],
    ];
}

/**
 * Best-effort cache write, called after a translation is published or
 * edited (api/admin/dispatch-translations/save.php and the auto-publish path
 * in pw_create_dispatch_translation_draft()). Skips re-encoding when the
 * translation text hasn't actually changed since it was last cached, and
 * never throws -- a missing or stale embedding just means this one Dispatch
 * won't surface as a future match, never a blocked save or publish.
 */
function pw_dispatch_update_translation_embedding(PDO $db, int $dispatchId, string $translation): void
{
    $translation = trim($translation);
    if ($translation === '') {
        return;
    }
    $hash = hash('sha256', $translation);
    try {
        $stmt = $db->prepare('SELECT translation_hash FROM dispatch_translation_embeddings WHERE dispatch_id = ?');
        $stmt->execute([$dispatchId]);
        $existingHash = $stmt->fetchColumn();
        if ($existingHash === $hash) {
            return;
        }
    } catch (PDOException $e) {
        return;
    }

    $result = pw_dispatch_embedding_similarity($translation);
    if (empty($result['embedding'])) {
        return;
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO dispatch_translation_embeddings (dispatch_id, model, translation_hash, embedding_json)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               model = VALUES(model),
               translation_hash = VALUES(translation_hash),
               embedding_json = VALUES(embedding_json)'
        );
        $stmt->execute([$dispatchId, $result['model'], $hash, json_encode($result['embedding'])]);
    } catch (PDOException $e) {
        // Optional migration may not be applied yet.
    }
}
