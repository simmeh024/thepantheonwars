<?php
/**
 * Manual, CSRF-protected refresh for the Development Snapshot language bar.
 * The public read endpoint keeps its 24-hour cache; this admin action is the
 * explicit escape hatch for immediately pulling current GitHub language data.
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dashboards.view_home');
$input = pw_input();
pw_require_csrf($input);

$url = 'https://api.github.com/repos/simmeh024/thepantheonwars/languages';
$opts = [
    'http' => [
        'method' => 'GET',
        'header' => pw_github_stream_header('ThePantheonWars-AdminConsole'),
        'timeout' => 8,
        'ignore_errors' => true,
    ],
];
$raw = @file_get_contents($url, false, stream_context_create($opts));
$languages = $raw === false ? null : json_decode($raw, true);
if (!is_array($languages) || empty($languages)) {
    pw_error('Could not retrieve language data from GitHub.', 502);
}

arsort($languages);
$totalBytes = array_sum($languages);
$out = [];
foreach ($languages as $name => $bytes) {
    $out[] = [
        'name' => $name,
        'bytes' => $bytes,
        'pct' => $totalBytes > 0 ? round(($bytes / $totalBytes) * 1000) / 10 : 0,
    ];
}

$capturedAt = gmdate('Y-m-d H:i:s');
$stmt = pw_db()->prepare(
    'INSERT INTO repo_language_snapshots (captured_at, total_bytes, languages_json) VALUES (?, ?, ?)'
);
$stmt->execute([$capturedAt, $totalBytes, json_encode($out)]);

pw_log_admin_activity('development_snapshot_refreshed', 'Manually refreshed Development Snapshot language data from GitHub.', $adminUser);
pw_json(['ok' => true, 'captured_at' => $capturedAt, 'languages' => $out]);
