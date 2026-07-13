<?php
/**
 * Shared Development Snapshot line-count helpers. Keeping the expensive
 * repository scan here lets both the dedicated LOC endpoint and the bundled
 * Home summary resolve the exact same daily snapshot.
 */

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

function pw_get_loc_stats($db, $forceRefresh = false) {
    $today = date('Y-m-d');
    $stmt = $db->prepare('SELECT total_lines FROM loc_snapshots WHERE captured_at = ?');
    $stmt->execute([$today]);
    $todayRow = $stmt->fetch();

    if ($todayRow && !$forceRefresh) {
        $totalLines = (int)$todayRow['total_lines'];
    } else {
        $totalLines = pw_compute_total_loc(PW_REPO_PATH);
        if ($totalLines === null) {
            return null;
        }
        $ins = $db->prepare(
            'INSERT INTO loc_snapshots (captured_at, total_lines) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE total_lines = VALUES(total_lines)'
        );
        $ins->execute([$today, $totalLines]);
    }

    $prevStmt = $db->prepare(
        'SELECT total_lines FROM loc_snapshots WHERE captured_at < ? ORDER BY captured_at DESC LIMIT 1'
    );
    $prevStmt->execute([$today]);
    $prevRow = $prevStmt->fetch();

    return [
        'total_lines' => $totalLines,
        'delta_today' => $prevRow ? ($totalLines - (int)$prevRow['total_lines']) : null,
    ];
}
