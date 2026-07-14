<?php
/**
 * Safe, aggregate context for the deterministic Dispatch Draft Translator.
 *
 * Raw GitHub file paths and source diffs never reach the database or reader
 * copy. We retain only a count plus small, allow-listed labels for file types
 * and product areas. All persistence helpers are optional until
 * migration_dispatch_diff_context.sql has been applied.
 */

function pw_dispatch_diff_context_from_paths(array $paths): array
{
    $paths = array_values(array_unique(array_filter($paths, static function ($path): bool {
        return is_string($path) && $path !== '';
    })));

    $extensionLabels = [
        'php' => 'site-service files',
        'html' => 'page templates',
        'css' => 'style files',
        'js' => 'interface scripts',
        'sql' => 'database definitions',
        'md' => 'project documentation',
        'json' => 'configuration files',
        'yml' => 'deployment configuration',
        'yaml' => 'deployment configuration',
        'png' => 'image assets',
        'jpg' => 'image assets',
        'jpeg' => 'image assets',
        'webp' => 'image assets',
        'svg' => 'image assets',
    ];
    $areaRules = [
        'admin/' => 'the Admin Console',
        'api/' => 'site services',
        'css/' => 'the visual interface',
        'js/' => 'interactive site behaviour',
        'sql/' => 'the site database',
        'images/' => 'site imagery',
        'uploads/' => 'member uploads',
        'community' => 'community features',
        'world' => 'worldbuilding pages',
        'book' => 'book content',
        'privacy' => 'privacy tools',
    ];
    $extensions = [];
    $areas = [];

    foreach ($paths as $path) {
        $normalized = strtolower(str_replace('\\', '/', $path));
        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if (isset($extensionLabels[$extension])) {
            $extensions[$extensionLabels[$extension]] = true;
        }
        foreach ($areaRules as $needle => $label) {
            if (strpos($normalized, $needle) !== false) {
                $areas[$label] = true;
            }
        }
        if (strpos($normalized, '/') === false && preg_match('/\.html?$/', $normalized)) {
            $areas['public pages'] = true;
        }
    }

    return [
        'files_changed' => min(65535, count($paths)),
        'extensions' => array_slice(array_keys($extensions), 0, 3),
        'areas' => array_slice(array_keys($areas), 0, 3),
    ];
}

function pw_store_dispatch_diff_context(PDO $db, int $dispatchId, array $context): void
{
    try {
        $stmt = $db->prepare(
            'INSERT INTO dispatch_diff_context (dispatch_id, files_changed, extensions_json, areas_json)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               files_changed = VALUES(files_changed),
               extensions_json = VALUES(extensions_json),
               areas_json = VALUES(areas_json)'
        );
        $stmt->execute([
            $dispatchId,
            (int)($context['files_changed'] ?? 0),
            json_encode(array_values($context['extensions'] ?? [])),
            json_encode(array_values($context['areas'] ?? [])),
        ]);
    } catch (PDOException $e) {
        // Optional until the accompanying migration is run in phpMyAdmin.
    }
}

function pw_get_dispatch_diff_contexts(PDO $db, array $dispatchIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $dispatchIds))));
    if (!$ids) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            'SELECT dispatch_id, files_changed, extensions_json, areas_json
             FROM dispatch_diff_context WHERE dispatch_id IN (' . $placeholders . ')'
        );
        $stmt->execute($ids);
        $contexts = [];
        foreach ($stmt->fetchAll() as $row) {
            $extensions = json_decode((string)$row['extensions_json'], true);
            $areas = json_decode((string)$row['areas_json'], true);
            $contexts[(int)$row['dispatch_id']] = [
                'files_changed' => (int)$row['files_changed'],
                'extensions' => is_array($extensions) ? $extensions : [],
                'areas' => is_array($areas) ? $areas : [],
            ];
        }
        return $contexts;
    } catch (PDOException $e) {
        return [];
    }
}

function pw_fetch_github_dispatch_diff_context(string $sha): array
{
    // A manual re-sync can discover a large historical gap. The normal
    // webhook already carries this metadata, so cap its catch-up lookups to
    // keep a one-off recovery action bounded on shared hosting.
    static $requests = 0;
    if ($requests >= 25) {
        return [];
    }
    $requests++;

    $url = 'https://api.github.com/repos/simmeh024/thepantheonwars/commits/' . rawurlencode($sha);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => pw_github_curl_headers(),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $status !== 200) {
        return [];
    }
    $payload = json_decode($response, true);
    if (!is_array($payload) || empty($payload['files']) || !is_array($payload['files'])) {
        return [];
    }
    return pw_dispatch_diff_context_from_paths(array_column($payload['files'], 'filename'));
}
