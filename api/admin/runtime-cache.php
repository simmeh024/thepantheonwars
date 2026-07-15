<?php
/**
 * Small shared cache for expensive, non-user-specific Admin Console probes.
 *
 * The cache table is intentionally optional during deploy: callers receive a
 * fresh value if its migration has not been run yet, so the console remains
 * functional while production is being updated.
 */

function pw_admin_runtime_cache_read(PDO $db, string $key): ?array
{
    try {
        $stmt = $db->prepare(
            'SELECT payload FROM admin_runtime_cache
             WHERE cache_key = ? AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row || !isset($row['payload'])) {
            return null;
        }
        $payload = json_decode((string)$row['payload'], true);
        return is_array($payload) ? $payload : null;
    } catch (Throwable $e) {
        return null;
    }
}

function pw_admin_runtime_cache_write(PDO $db, string $key, array $payload, int $ttlSeconds): void
{
    if ($ttlSeconds < 1) {
        return;
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return;
    }
    try {
        $stmt = $db->prepare(
            'INSERT INTO admin_runtime_cache (cache_key, payload, expires_at, updated_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               payload = VALUES(payload),
               expires_at = VALUES(expires_at),
               updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([$key, $encoded, gmdate('Y-m-d H:i:s', time() + $ttlSeconds)]);
    } catch (Throwable $e) {
        // The cache is an optimization only. A failed write must never make a
        // status check or dashboard response unavailable.
    }
}

function pw_admin_runtime_cache_remember(PDO $db, string $key, int $ttlSeconds, callable $resolver, bool $forceFresh = false): array
{
    if (!$forceFresh) {
        $cached = pw_admin_runtime_cache_read($db, $key);
        if ($cached !== null) {
            return $cached;
        }
    }
    $payload = $resolver();
    if (!is_array($payload)) {
        return [];
    }
    pw_admin_runtime_cache_write($db, $key, $payload, $ttlSeconds);
    return $payload;
}
