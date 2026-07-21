<?php
/**
 * Optional local sentence-embedding bridge for Dispatch Translation semantic
 * similarity, mirroring api/dispatch-spacy.php's fail-open shape but talking
 * to a persistent local service over curl instead of proc_open-ing a
 * one-shot script -- see tools/dispatch-embeddings-service.py and
 * docs/dispatch-embeddings.md for why this needs to be a persistent process.
 *
 * This bridge itself never exposes a public endpoint, and the request it
 * sends never leaves the hosting account's own server. Unlike a self-managed
 * host, cPanel's "Setup Python App" (see docs/dispatch-embeddings.md) always
 * routes a Python app through a real URL path on the account's own domain --
 * there is no raw loopback-port access -- so DISPATCH_EMBEDDING_SERVICE_KEY
 * is sent as a shared-secret header on every request, checked by the Python
 * side, so the endpoint can't be used by anyone who merely finds its URL.
 *
 * If the service is unconfigured, unreachable, unauthorized, or returns
 * anything unexpected, every function here fails open to an empty result --
 * the deterministic translator then produces exactly its established
 * fallback, with zero change to confidence, wording, or publication
 * decisions.
 */

/**
 * Shared request helper. Returns the decoded response body (already
 * confirmed to have `ok: true`) or null on any failure. A connection-level
 * failure (refused, timed out, DNS) latches $unavailable for the rest of
 * this request, same as api/dispatch-spacy.php's pattern -- there is no
 * point retrying a service that just failed to connect.
 */
function pw_dispatch_embedding_request(string $path, ?array $body): ?array
{
    static $unavailable = false;
    if ($unavailable || !function_exists('curl_init') || !defined('DISPATCH_EMBEDDING_SERVICE_URL')) {
        return null;
    }

    $base = trim((string)DISPATCH_EMBEDDING_SERVICE_URL);
    if ($base === '') {
        $unavailable = true;
        return null;
    }

    $ch = curl_init(rtrim($base, '/') . $path);
    if ($ch === false) {
        $unavailable = true;
        return null;
    }
    $headers = ['X-Dispatch-Key: ' . (defined('DISPATCH_EMBEDDING_SERVICE_KEY') ? (string)DISPATCH_EMBEDDING_SERVICE_KEY : '')];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        // Short and strict: this is a warm local service, not a cold worker
        // startup. A slow/unresponsive service should fail fast, not eat
        // into the same request budget api/dispatch-spacy.php protects.
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            curl_close($ch);
            return null;
        }
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $payload;
        $options[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/json']);
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        $unavailable = true;
        return null;
    }
    if ($httpCode !== 200) {
        return null;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) && !empty($decoded['ok']) ? $decoded : null;
}

/**
 * Encode one string. Only ever called with the current incoming commit's
 * cleaned text, or (at publish/edit time) one just-approved translation's
 * text -- never a batch of prior translations. The cached corpus itself
 * lives in dispatch_translation_embeddings, computed by PHP from these
 * single-string results, never sent back to this service as a whole.
 */
function pw_dispatch_embedding_similarity(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $decoded = pw_dispatch_embedding_request('/encode', ['text' => mb_substr($text, 0, 4000, 'UTF-8')]);
    if ($decoded === null || !is_array($decoded['embedding'] ?? null)) {
        return [];
    }
    return [
        'embedding' => array_map('floatval', $decoded['embedding']),
        'model' => isset($decoded['model']) ? (string)$decoded['model'] : '',
    ];
}

/**
 * System Status uses a real request rather than merely checking whether the
 * config constant exists, matching pw_dispatch_spacy_status()'s reasoning --
 * this catches a stopped Passenger app, a removed venv, or a hung model load
 * alike. Deliberately not wired into BH-4's critical-directive escalation
 * the way spaCy is: this signal is additive and optional, never load-bearing
 * for publication, so a disconnected service is a System Status row, not an
 * admin alert.
 */
function pw_dispatch_embedding_status(): array
{
    $decoded = pw_dispatch_embedding_request('/health', null);
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
