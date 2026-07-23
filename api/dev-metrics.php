<?php
/**
 * Public, read-only data source for Development Metrics. Keeping the
 * aggregation here avoids loading a capped dispatch-list page in the browser
 * and gives the page one consistent snapshot for its totals, activity and
 * drill-downs.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/dispatch-helpers.php';
require_once __DIR__ . '/dispatch-diff-context.php';

date_default_timezone_set('UTC');

$startRaw = isset($_GET['start']) ? trim($_GET['start']) : '';
$endRaw = isset($_GET['end']) ? trim($_GET['end']) : '';
$startTs = strtotime($startRaw);
$endTs = strtotime($endRaw);
if ($startRaw === '' || $endRaw === '' || $startTs === false || $endTs === false || $endTs <= $startTs) {
    pw_error('A valid start and end date are required.');
}

$start = gmdate('Y-m-d H:i:s', $startTs);
$end = gmdate('Y-m-d H:i:s', $endTs);
$previousStart = gmdate('Y-m-d H:i:s', $startTs - ($endTs - $startTs));

$db = pw_db();
$tags = ['feature', 'improvement', 'fix', 'performance', 'ui_ux', 'lore', 'infrastructure', 'refactor', 'experimental'];
$visibilitySql = pw_dispatch_has_visibility_column($db) ? 'd.is_hidden = 0 AND ' : '';
$rangeSql = $visibilitySql . 'd.committed_at >= :start AND d.committed_at < :end';
$previousSql = $visibilitySql . 'd.committed_at >= :previous_start AND d.committed_at < :start';

function pw_dev_metric_counts($db, $whereSql, $params, $tags) {
    $counts = array_fill_keys($tags, 0);
    $stmt = $db->prepare('SELECT d.tag, COUNT(*) AS count FROM dispatch_entries d WHERE ' . $whereSql . ' GROUP BY d.tag');
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists($row['tag'], $counts)) {
            $counts[$row['tag']] = (int)$row['count'];
        }
    }
    return $counts;
}

$currentParams = [':start' => $start, ':end' => $end];
$previousParams = [':previous_start' => $previousStart, ':start' => $start];
$counts = pw_dev_metric_counts($db, $rangeSql, $currentParams, $tags);
$previousCounts = pw_dev_metric_counts($db, $previousSql, $previousParams, $tags);
$total = array_sum($counts);
$previousTotal = array_sum($previousCounts);

$activityStmt = $db->prepare(
    'SELECT DATE(d.committed_at) AS day, COUNT(*) AS count FROM dispatch_entries d WHERE ' . $rangeSql
    . ' GROUP BY DATE(d.committed_at) ORDER BY day ASC'
);
$activityStmt->execute($currentParams);
$activity = array_map(function ($row) {
    return ['day' => $row['day'], 'count' => (int)$row['count']];
}, $activityStmt->fetchAll());

$entryStmt = $db->prepare(
    'SELECT d.id, d.sha, d.subject, d.tag, d.author, d.committed_at, d.url FROM dispatch_entries d WHERE ' . $rangeSql
    . ' ORDER BY d.committed_at DESC, d.id DESC'
);
$entryStmt->execute($currentParams);
$entries = array_map(function ($row) {
    return [
        'id' => (int)$row['id'],
        'short_sha' => substr($row['sha'], 0, 7),
        'subject' => $row['subject'],
        'tag' => $row['tag'],
        'author' => $row['author'],
        'committed_at' => $row['committed_at'],
        'url' => $row['url'],
    ];
}, $entryStmt->fetchAll());

// Diff context is optional until its migration has been run; the helper
// safely returns an empty map in that case. The public page receives only the
// aggregate file count, never source paths or diff content.
$contexts = pw_get_dispatch_diff_contexts($db, array_column($entries, 'id'));
foreach ($entries as &$entry) {
    $entry['files_changed'] = isset($contexts[$entry['id']]) ? (int)$contexts[$entry['id']]['files_changed'] : null;
}
unset($entry);

$latest = array_slice($entries, 0, 5);

$latestStmt = $db->query(
    'SELECT d.sha, d.committed_at, d.created_at FROM dispatch_entries d WHERE 1=1 '
    . (pw_dispatch_has_visibility_column($db) ? 'AND d.is_hidden = 0 ' : '')
    . 'ORDER BY d.committed_at DESC, d.id DESC LIMIT 1'
);
$latestRow = $latestStmt->fetch();

// Total lines are captured by the existing daily admin snapshot process. The
// public page only reads that durable history; it never triggers a server scan.
$locStmt = $db->prepare(
    'SELECT captured_at, total_lines FROM loc_snapshots WHERE captured_at <= :end_day ORDER BY captured_at DESC LIMIT 90'
);
$locStmt->execute([':end_day' => gmdate('Y-m-d', $endTs)]);
$locRows = array_reverse($locStmt->fetchAll());
$locHistory = array_map(function ($row) {
    return ['day' => $row['captured_at'], 'total_lines' => (int)$row['total_lines']];
}, $locRows);
$latestLoc = $locHistory ? $locHistory[count($locHistory) - 1]['total_lines'] : null;

pw_json([
    'ok' => true,
    'total' => $total,
    'previous_total' => $previousTotal,
    'counts' => $counts,
    'previous_counts' => $previousCounts,
    'activity' => $activity,
    'entries' => $entries,
    'latest' => $latest,
    'status' => [
        'state' => $latestRow ? 'current' : 'waiting',
        'latest_sha' => $latestRow ? substr($latestRow['sha'], 0, 7) : null,
        'latest_committed_at' => $latestRow ? $latestRow['committed_at'] : null,
        'last_synced_at' => $latestRow ? $latestRow['created_at'] : null,
        'generated_at' => gmdate('Y-m-d H:i:s'),
    ],
    'code_growth' => [
        'latest_lines' => $latestLoc,
        'history' => $locHistory,
    ],
]);
