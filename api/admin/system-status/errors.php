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
    $debugCandidates = [];
    $iniPath = ini_get('error_log');
    $debugCandidates[] = ['path' => $iniPath, 'source' => 'ini_get'];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
        $debugCandidates[] = ['path' => $docRoot . '/error_log', 'source' => 'docroot'];
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'thepantheonwars.com';
        $debugCandidates[] = ['path' => dirname($docRoot) . '/logs/' . $host, 'source' => 'cpanel-logs'];
    }
    foreach ($debugCandidates as &$c) {
        $c['exists'] = $c['path'] ? file_exists($c['path']) : false;
        $c['readable'] = $c['path'] ? is_readable($c['path']) : false;
    }
    unset($c);
    pw_json([
        'ok' => true,
        'available' => false,
        'entries' => [],
        'total' => 0,
        'page' => 1,
        'total_pages' => 1,
        'debug_document_root' => isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : null,
        'debug_candidates' => $debugCandidates,
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
