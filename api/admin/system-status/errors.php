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
    // A live check of ~/logs/* on this host turned up only access logs
    // (thepantheonwars.com-Jul-2026.gz, -ssl_log-...) -- no error log. The
    // Errors page in cPanel (Metrics > Errors) can still show Apache's error
    // log because cPanel's backend reads it with elevated privileges; a
    // plain PHP script running as this account can't reach that same file.
    // Short of the account owner setting a custom writable error_log path
    // (cPanel > MultiPHP INI Editor > error_log = /home/.../php_errors.log),
    // there's no PHP-readable log to tail here, so this stays honest about
    // being unavailable rather than guessing at more paths.
    pw_json([
        'ok' => true,
        'available' => false,
        'entries' => [],
        'total' => 0,
        'page' => 1,
        'total_pages' => 1,
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
