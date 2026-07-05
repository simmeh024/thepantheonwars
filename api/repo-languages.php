<?php
require_once __DIR__ . '/helpers.php';

const PW_LANG_SNAPSHOT_TTL = 21600; // pull/push from GitHub at most once every 6 hours

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

function pw_langs_to_out($langs) {
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
    return [$out, $total];
}

$db = pw_db();

// --- Step 1: record a fresh snapshot if the last one is stale (or missing) ---
$latest = $db->query('SELECT captured_at FROM repo_language_snapshots ORDER BY captured_at DESC LIMIT 1')->fetch();
$needsRefresh = true;
if ($latest) {
    $age = time() - strtotime($latest['captured_at']);
    if ($age < PW_LANG_SNAPSHOT_TTL) {
        $needsRefresh = false;
    }
}

if ($needsRefresh) {
    $fresh = pw_fetch_github_languages();
    if ($fresh !== null && !empty($fresh)) {
        list($out, $total) = pw_langs_to_out($fresh);
        $stmt = $db->prepare('INSERT INTO repo_language_snapshots (captured_at, total_bytes, languages_json) VALUES (NOW(), :total, :json)');
        $stmt->execute([':total' => $total, ':json' => json_encode($out)]);
    }
}

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

pw_json([
    'ok' => true,
    'found' => true,
    'languages' => $languages,
    'captured_at' => $row['captured_at'],
    'total_bytes' => (int)$row['total_bytes'],
]);
