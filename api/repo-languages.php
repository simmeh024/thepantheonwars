<?php
require_once __DIR__ . '/helpers.php';

$cacheFile = __DIR__ . '/../uploads/repo-languages-cache.json';
$cacheTtl = 3600; // 1 hour

function pw_fetch_github_languages() {
    $url = 'https://api.github.com/repos/simmeh024/thepantheonwars/languages';
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: ThePantheonWars-Site\r\nAccept: application/vnd.github+json\r\n",
            'timeout' => 6,
            'ignore_errors' => true,
        ],
    ];
    $context = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    return $data;
}

$langs = null;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cached)) {
        $langs = $cached;
    }
}

if ($langs === null) {
    $fresh = pw_fetch_github_languages();
    if ($fresh !== null && !empty($fresh)) {
        $langs = $fresh;
        @file_put_contents($cacheFile, json_encode($fresh));
    } elseif (file_exists($cacheFile)) {
        // GitHub call failed or returned empty; serve stale cache if we have one
        $stale = json_decode(file_get_contents($cacheFile), true);
        if (is_array($stale)) {
            $langs = $stale;
        }
    }
}

if ($langs === null) {
    pw_error('Could not load language stats right now.', 502);
}

$total = array_sum($langs);
$out = [];
if ($total > 0) {
    arsort($langs);
    foreach ($langs as $name => $bytes) {
        $out[] = [
            'name' => $name,
            'bytes' => $bytes,
            'pct' => round(($bytes / $total) * 1000) / 10,
        ];
    }
}

pw_json([
    'ok' => true,
    'total_bytes' => $total,
    'languages' => $out,
]);
