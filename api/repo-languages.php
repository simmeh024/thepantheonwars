<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/repo-languages-helpers.php';

date_default_timezone_set('UTC'); // store and compare snapshot times in UTC, unambiguously

function pw_bucket_key($group, $ts) {
    switch ($group) {
        case 'week':
            return date('o', $ts) . '-W' . date('W', $ts);
        case 'month':
            return date('Y-m', $ts);
        case 'year':
            return date('Y', $ts);
        case 'day':
        default:
            return date('Y-m-d', $ts);
    }
}

function pw_bucket_label($group, $ts) {
    switch ($group) {
        case 'week': {
            $dow = (int)date('N', $ts); // 1 (Mon) .. 7 (Sun)
            $mondayTs = $ts - (($dow - 1) * 86400);
            return 'Week of ' . date('M j', $mondayTs);
        }
        case 'month':
            return date('M Y', $ts);
        case 'year':
            return date('Y', $ts);
        case 'day':
        default:
            return date('M j', $ts);
    }
}

$db = pw_db();

// --- Step 1: record a fresh snapshot if the last one is stale (or missing) ---
pw_ensure_repo_language_snapshot($db);

// --- Step 2: resolve the requested date range -------------------------------
$start = isset($_GET['start']) ? trim($_GET['start']) : '';
$end = isset($_GET['end']) ? trim($_GET['end']) : '';

$startSql = null;
$endSql = null;
if ($start !== '' && strtotime($start) !== false) {
    $startSql = date('Y-m-d H:i:s', strtotime($start));
}
if ($end !== '' && strtotime($end) !== false) {
    $endSql = date('Y-m-d H:i:s', strtotime($end));
}

$where = [];
$params = [];
if ($startSql !== null) {
    $where[] = 'captured_at >= :start';
    $params[':start'] = $startSql;
}
if ($endSql !== null) {
    $where[] = 'captured_at <= :end';
    $params[':end'] = $endSql;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// --- Step 2b: series mode -- grouped history for the stacked bar chart ------
$group = isset($_GET['group']) ? trim($_GET['group']) : '';
$validGroups = ['day', 'week', 'month', 'year'];
if ($group !== '' && in_array($group, $validGroups, true)) {
    $stmtSeries = $db->prepare("SELECT captured_at, total_bytes, languages_json FROM repo_language_snapshots $whereSql ORDER BY captured_at ASC");
    $stmtSeries->execute($params);
    $rows = $stmtSeries->fetchAll();

    $buckets = []; // keyed by bucket key; last row seen per key wins (latest snapshot in that period)
    foreach ($rows as $r) {
        $ts = strtotime($r['captured_at']);
        $key = pw_bucket_key($group, $ts);
        $buckets[$key] = [
            'label' => pw_bucket_label($group, $ts),
            'captured_at' => $r['captured_at'],
            'total_bytes' => (int)$r['total_bytes'],
            'languages_json' => $r['languages_json'],
        ];
    }

    ksort($buckets);

    $series = [];
    foreach ($buckets as $b) {
        $langs = json_decode($b['languages_json'], true);
        if (!is_array($langs)) {
            $langs = [];
        }
        $series[] = [
            'label' => $b['label'],
            'captured_at' => $b['captured_at'],
            'total_bytes' => $b['total_bytes'],
            'languages' => $langs,
        ];
    }

    pw_json([
        'ok' => true,
        'series' => true,
        'group' => $group,
        'buckets' => $series,
    ]);
}

$stmt = $db->prepare("SELECT captured_at, total_bytes, languages_json FROM repo_language_snapshots $whereSql ORDER BY captured_at DESC LIMIT 1");
$stmt->execute($params);
$row = $stmt->fetch();

if (!$row) {
    pw_json([
        'ok' => true,
        'found' => false,
        'languages' => [],
        'captured_at' => null,
        'total_bytes' => 0,
    ]);
}

$languages = json_decode($row['languages_json'], true);
if (!is_array($languages)) {
    $languages = [];
}

$nextSyncAt = date('Y-m-d H:i:s', strtotime($row['captured_at']) + PW_LANG_SNAPSHOT_TTL);

pw_json([
    'ok' => true,
    'found' => true,
    'languages' => $languages,
    'captured_at' => $row['captured_at'],
    'next_sync_at' => $nextSyncAt,
    'total_bytes' => (int)$row['total_bytes'],
]);
