<?php
/**
 * Total lines-of-code figure for the admin Home page's Development Snapshot
 * card, plus a delta vs. the most recent prior snapshot ("+N today").
 *
 * Counts lines across every git-tracked text file in the deployed repo
 * (html/js/css/php/sql/md -- source, not binary assets), via the same
 * shell_exec pattern already used for real disk usage in
 * api/admin/system-status/status-helpers.php (shell_exec is confirmed
 * available on this host, see CLAUDE.md's "Server introspection notes").
 * Snapshots are stored once per calendar day (loc_snapshots, captured_at
 * DATE UNIQUE) so the potentially-slow full-repo scan only runs once a day
 * rather than on every Home page load, mirroring api/repo-languages.php's
 * lazy-snapshot approach.
 */
require_once __DIR__ . '/../helpers.php';

pw_require_permission('dashboards.view_home');

$forceRefresh = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = pw_input();
    pw_require_csrf($input);
    $forceRefresh = true;
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pw_error('Method not allowed.', 405);
}

const PW_REPO_PATH = '/home/rdy3i6my40b0/repositories/thepantheonwars';
const PW_LOC_EXTENSIONS = ['php', 'html', 'js', 'css', 'sql', 'md', 'json', 'yml', 'yaml'];

function pw_compute_total_loc($repoPath) {
    if (!function_exists('shell_exec')) {
        return null;
    }
    $output = @shell_exec('cd ' . escapeshellarg($repoPath) . ' && git ls-files 2>/dev/null');
    if ($output === null || trim($output) === '') {
        return null;
    }

    $total = 0;
    foreach (explode("\n", trim($output)) as $rel) {
        $rel = trim($rel);
        if ($rel === '') {
            continue;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, PW_LOC_EXTENSIONS, true)) {
            continue;
        }
        $abs = $repoPath . '/' . $rel;
        if (!is_file($abs)) {
            continue;
        }
        $content = @file_get_contents($abs);
        if ($content === false || $content === '') {
            continue;
        }
        $total += substr_count($content, "\n");
        if (substr($content, -1) !== "\n") {
            $total++;
        }
    }
    return $total;
}

$db = pw_db();
$today = date('Y-m-d');

$stmt = $db->prepare('SELECT total_lines FROM loc_snapshots WHERE captured_at = ?');
$stmt->execute([$today]);
$todayRow = $stmt->fetch();

if ($todayRow && !$forceRefresh) {
    $totalLines = (int)$todayRow['total_lines'];
} else {
    $totalLines = pw_compute_total_loc(PW_REPO_PATH);
    if ($totalLines === null) {
        pw_json(['ok' => false, 'error' => 'Could not compute lines of code on this host.']);
    }
    $ins = $db->prepare(
        'INSERT INTO loc_snapshots (captured_at, total_lines) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE total_lines = VALUES(total_lines)'
    );
    $ins->execute([$today, $totalLines]);
}

$prevStmt = $db->prepare(
    'SELECT total_lines FROM loc_snapshots WHERE captured_at < ? ORDER BY captured_at DESC LIMIT 1'
);
$prevStmt->execute([$today]);
$prevRow = $prevStmt->fetch();
$deltaToday = $prevRow ? ($totalLines - (int)$prevRow['total_lines']) : null;

pw_json([
    'ok' => true,
    'total_lines' => $totalLines,
    'delta_today' => $deltaToday,
]);
