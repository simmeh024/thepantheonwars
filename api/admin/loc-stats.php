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
require_once __DIR__ . '/loc-stats-helpers.php';

pw_require_permission('dashboards.view_home');

$forceRefresh = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = pw_input();
    pw_require_csrf($input);
    $forceRefresh = true;
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pw_error('Method not allowed.', 405);
}

$db = pw_db();
$stats = pw_get_loc_stats($db, $forceRefresh);
if ($stats === null) {
    pw_json(['ok' => false, 'error' => 'Could not compute lines of code on this host.']);
}

pw_json([
    'ok' => true,
    'total_lines' => $stats['total_lines'],
    'delta_today' => $stats['delta_today'],
]);
