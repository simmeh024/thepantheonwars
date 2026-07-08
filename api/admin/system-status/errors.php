<?php
/**
 * Full paginated PHP error log listing for the System Status page (mirrors
 * the pagination shape of activity-log/list.php so the frontend can reuse
 * the same admin-pager pattern). Supports ?severity=critical to show only
 * Fatal/Parse/Uncaught entries, same idea as the Audit Log's action filter.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/status-helpers.php';

pw_require_admin();

$severity = isset($_GET['severity']) ? trim((string)$_GET['severity']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 25;

$data = pw_load_error_entries();

if (!$data['available']) {
    // Temporary debug block to pin down the real log path on this host --
    // remove once pw_error_log_path()'s candidate list is confirmed correct.
    $iniPath = ini_get('error_log');
    $relativeLogName = ($iniPath && strpos($iniPath, '/') === false) ? $iniPath : 'error_log';
    $debugCandidates = [];
    if ($iniPath && strpos($iniPath, '/') !== false) {
        $debugCandidates[] = $iniPath;
    }
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
        $debugCandidates[] = $docRoot . '/' . $relativeLogName;
        $debugCandidates[] = $docRoot . '/api/' . $relativeLogName;
        $debugCandidates[] = $docRoot . '/api/admin/' . $relativeLogName;
        $debugCandidates[] = $docRoot . '/api/admin/system-status/' . $relativeLogName;
        $debugCandidates[] = $docRoot . '/admin/' . $relativeLogName;
        $home = dirname($docRoot);
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'thepantheonwars.com';
        $bareHost = preg_replace('/^www\./', '', $host);
        $debugCandidates[] = $home . '/' . $relativeLogName;
        $debugCandidates[] = $home . '/logs/' . $host;
        $debugCandidates[] = $home . '/logs/' . $bareHost;
        $debugCandidates[] = $home . '/php_errorlog';
    }
    $debugCandidates[] = __DIR__ . '/' . $relativeLogName;

    $debugInfo = [];
    foreach ($debugCandidates as $c) {
        $debugInfo[] = ['path' => $c, 'exists' => file_exists($c), 'readable' => is_readable($c)];
    }
    pw_json([
        'ok' => true,
        'available' => false,
        'entries' => [],
        'total' => 0,
        'page' => 1,
        'total_pages' => 1,
        'debug_ini_error_log' => $iniPath,
        'debug_candidates' => $debugInfo,
    ]);
}

$entries = $data['entries'];
if ($severity === 'critical') {
    $entries = array_values(array_filter($entries, function ($e) {
        return $e['critical'];
    }));
}

$total = count($entries);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$pageEntries = array_slice($entries, $offset, $perPage);

pw_json([
    'ok' => true,
    'available' => true,
    'entries' => $pageEntries,
    'total' => $total,
    'page' => $page,
    'total_pages' => $totalPages,
]);
