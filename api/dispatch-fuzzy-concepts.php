<?php
/**
 * Reviewed aliases used by the optional local RapidFuzz matcher.
 *
 * This file deliberately keeps the public wording in PHP's control. Python
 * receives only ids and aliases; it can report a likely concept but can never
 * supply prose or bypass the normal editorial and confidence safeguards.
 */

function pw_dispatch_fuzzy_concepts(): array
{
    static $concepts = null;
    if ($concepts !== null) {
        return $concepts;
    }

    $concepts = [];
    $path = dirname(__DIR__) . '/tools/dispatch-fuzzy-concepts.json';
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return $concepts;
    }

    foreach (array_slice($decoded, 0, 80) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = isset($item['id']) ? trim((string)$item['id']) : '';
        $readerObject = isset($item['reader_object']) ? trim((string)$item['reader_object']) : '';
        $aliases = is_array($item['aliases'] ?? null) ? $item['aliases'] : [];
        if (!preg_match('/^[a-z0-9_]{3,64}$/', $id) || $readerObject === '') {
            continue;
        }
        $aliases = array_values(array_filter(array_map(static function ($alias): string {
            return is_string($alias) ? trim($alias) : '';
        }, array_slice($aliases, 0, 12)), static function (string $alias): bool {
            return strlen($alias) >= 4 && strlen($alias) <= 100;
        }));
        if (!$aliases) {
            continue;
        }
        $concepts[$id] = [
            'id' => $id,
            'aliases' => $aliases,
            'reader_object' => $readerObject,
        ];
    }

    return $concepts;
}

function pw_dispatch_fuzzy_worker_concepts(): array
{
    return array_map(static function (array $concept): array {
        return [
            'id' => $concept['id'],
            'aliases' => $concept['aliases'],
        ];
    }, array_values(pw_dispatch_fuzzy_concepts()));
}

function pw_dispatch_fuzzy_concept_from_analysis(array $analysis): ?array
{
    $match = is_array($analysis['fuzzy_concept'] ?? null) ? $analysis['fuzzy_concept'] : [];
    $id = isset($match['id']) ? (string)$match['id'] : '';
    $score = isset($match['score']) ? (int)$match['score'] : 0;
    $concepts = pw_dispatch_fuzzy_concepts();
    if ($id === '' || $score < 92 || !isset($concepts[$id])) {
        return null;
    }
    return $concepts[$id] + ['score' => min(100, $score)];
}
