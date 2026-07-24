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

function pw_get_delivery_7d_stats($db, $currentTotalLines = null) {
    $startDate = date('Y-m-d', strtotime('-6 days'));
    $baselineDate = date('Y-m-d', strtotime('-7 days'));
    $days = [];
    for ($offset = 0; $offset < 7; $offset++) {
        $date = date('Y-m-d', strtotime($startDate . ' +' . $offset . ' days'));
        $days[$date] = ['date' => $date, 'dispatches' => 0];
    }

    $dispatchStmt = $db->prepare(
        'SELECT DATE(committed_at) AS date, COUNT(*) AS dispatches '
        . 'FROM dispatch_entries WHERE committed_at >= ? GROUP BY DATE(committed_at)'
    );
    $dispatchStmt->execute([$startDate . ' 00:00:00']);
    while ($row = $dispatchStmt->fetch()) {
        if (isset($days[$row['date']])) {
            $days[$row['date']]['dispatches'] = (int)$row['dispatches'];
        }
    }

    $dayList = array_values($days);
    $totalDispatches = 0;
    $busiestDay = null;
    foreach ($dayList as $day) {
        $totalDispatches += $day['dispatches'];
        if ($day['dispatches'] > 0 && ($busiestDay === null || $day['dispatches'] > $busiestDay['dispatches'])) {
            $busiestDay = $day;
        }
    }

    $netLocChange = null;
    if ($currentTotalLines !== null) {
        $baselineStmt = $db->prepare(
            'SELECT total_lines FROM loc_snapshots WHERE captured_at <= ? ORDER BY captured_at DESC LIMIT 1'
        );
        $baselineStmt->execute([$baselineDate]);
        $baselineRow = $baselineStmt->fetch();
        if ($baselineRow) {
            $netLocChange = $currentTotalLines - (int)$baselineRow['total_lines'];
        }
    }

    return [
        'days' => $dayList,
        'total_dispatches' => $totalDispatches,
        'busiest_day' => $busiestDay,
        'net_loc_change' => $netLocChange,
    ];
}
