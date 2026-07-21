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
    // TEMPORARY diagnostic logging -- see PW_DEBUG_EMBEDDING note on
    // pw_dispatch_update_translation_embedding() above.
    $debug = getenv('PW_DEBUG_EMBEDDING') !== false;

    static $unavailable = false;
    if ($unavailable) {
        if ($debug) {
            fwrite(STDERR, "[embed request] latched unavailable, skipping without trying\n");
        }
        return null;
    }
    if (!function_exists('proc_open') || !defined('DISPATCH_EMBEDDING_PYTHON_BIN')) {
        if ($debug) {
            fwrite(STDERR, "[embed request] proc_open missing or DISPATCH_EMBEDDING_PYTHON_BIN undefined\n");
        }
        return null;
    }

    $python = trim((string)DISPATCH_EMBEDDING_PYTHON_BIN);
    $script = dirname(__DIR__) . '/tools/dispatch-embeddings.py';
    if ($python === '' || !is_file($python) || !is_file($script)) {
        if ($debug) {
            fwrite(STDERR, "[embed request] pre-flight failed: python='{$python}' is_file(python)=" . (is_file($python) ? '1' : '0') . " is_file(script)=" . (is_file($script) ? '1' : '0') . "\n");
        }
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
        // Deliberately not latching here either: a spawn failure on this
        // account can be transient process-count pressure (this account has
        // a hard OS-level ceiling on simultaneous processes), not proof the
        // worker itself is broken. Same reasoning as the timeout branch
        // below -- only the pre-flight interpreter/script checks above are
        // genuinely static for the life of this process.
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
        // Deliberately not latching $unavailable here: a single slow cold
        // start is a per-call timing variance, not proof the worker is
        // broken. Latching it would be harmless for a normal web request
        // (each request is its own fresh PHP process anyway), but it
        // silently disabled every remaining row of a CLI batch script that
        // loops over hundreds of dispatches in one long-running process --
        // one slow row would otherwise kill embedding lookups for the rest
        // of that entire run. Only latch for failures that are actually
        // permanent within this process (missing interpreter/script above,
        // or a real script error below).
    }
    $output .= stream_get_contents($pipes[1]);
    $errors .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $decoded = json_decode($output, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        if ($debug) {
            fwrite(STDERR, "[embed request] decode failed. output=" . var_export($output, true) . " errors=" . var_export($errors, true) . "\n");
        }
        // Deliberately not latching here anymore either, for the same
        // per-call-vs-permanent reasoning as the branches above -- see
        // pw_dispatch_update_translation_embedding()'s debug logging for
        // why a single early failure must not silently disable an entire
        // long-running batch run.
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
    // A dispatch an admin has rated more Bad than Good must never be
    // recommended as a "similar past Dispatch" reference again -- that would
    // be actively steering an editor toward wording already known to be
    // wrong. The feedback table is a separate, newer migration than the
    // embeddings cache itself, so this exclusion degrades independently:
    // if it's not there yet, matching still works, just without this filter,
    // rather than going dark entirely until both migrations are applied.
    try {
        $stmt = $db->prepare(
            'SELECT dte.dispatch_id, dte.embedding_json, dt.translation, de.subject
             FROM dispatch_translation_embeddings dte
             INNER JOIN dispatch_translations dt ON dt.dispatch_id = dte.dispatch_id
             INNER JOIN dispatch_entries de ON de.id = dte.dispatch_id
             LEFT JOIN dispatch_translation_feedback dtf ON dtf.dispatch_id = dte.dispatch_id
             WHERE dte.dispatch_id <> ?
             GROUP BY dte.dispatch_id, dte.embedding_json, dt.translation, de.subject
             HAVING SUM(dtf.rating = \'bad\') <= SUM(dtf.rating = \'good\')'
        );
        $stmt->execute([$excludeDispatchId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
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
        } catch (PDOException $e2) {
            // The embeddings migration itself may not be applied yet.
            return [];
        }
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
    // TEMPORARY diagnostic logging, gated by an env var so normal production
    // behavior is unaffected. Enable with:
    //   PW_DEBUG_EMBEDDING=1 php tools/backfill-dispatch-embeddings.php
    $debug = getenv('PW_DEBUG_EMBEDDING') !== false;
    $log = static function (string $message) use ($debug, $dispatchId): void {
        if ($debug) {
            fwrite(STDERR, "[embed dispatch_id={$dispatchId}] {$message}\n");
        }
    };

    $translation = trim($translation);
    if ($translation === '') {
        $log('empty translation, skipping');
        return;
    }
    $hash = hash('sha256', $translation);
    try {
        $stmt = $db->prepare('SELECT translation_hash FROM dispatch_translation_embeddings WHERE dispatch_id = ?');
        $stmt->execute([$dispatchId]);
        $existingHash = $stmt->fetchColumn();
        if ($existingHash === $hash) {
            $log('hash already cached, skipping');
            return;
        }
        $log('hash check passed, existingHash=' . var_export($existingHash, true));
    } catch (PDOException $e) {
        $log('PDOException on hash SELECT: ' . $e->getMessage());
        return;
    }

    $result = pw_dispatch_embedding_similarity($translation);
    if (empty($result['embedding'])) {
        $log('pw_dispatch_embedding_similarity() returned no embedding, result=' . var_export($result, true));
        return;
    }
    $log('embedding obtained, length=' . count($result['embedding']));

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
        $log('INSERT/UPDATE succeeded, rowCount=' . $stmt->rowCount());
    } catch (PDOException $e) {
        $log('PDOException on INSERT: ' . $e->getMessage());
    }
}
