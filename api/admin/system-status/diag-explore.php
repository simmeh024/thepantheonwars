<?php
/**
 * TEMPORARY diagnostic-only endpoint. Not linked from any UI. Admin-gated.
 * Empirically tests what system/DB introspection is actually available on
 * this specific shared-hosting account, to inform which System Status
 * checks are feasible to build. Delete after use.
 */
require_once __DIR__ . '/../../helpers.php';
pw_require_admin();

$out = [];

// ---- CPU / system load ----
$out['sys_getloadavg'] = function_exists('sys_getloadavg') ? sys_getloadavg() : 'function_not_exists';
$out['proc_loadavg_readable'] = is_readable('/proc/loadavg');
if ($out['proc_loadavg_readable']) {
    $out['proc_loadavg_content'] = @file_get_contents('/proc/loadavg');
}
$out['proc_meminfo_readable'] = is_readable('/proc/meminfo');
if ($out['proc_meminfo_readable']) {
    $mem = @file_get_contents('/proc/meminfo');
    $out['proc_meminfo_sample'] = $mem !== false ? implode("\n", array_slice(explode("\n", $mem), 0, 5)) : null;
}
$out['proc_cpuinfo_readable'] = is_readable('/proc/cpuinfo');
$out['nproc_via_cpuinfo'] = null;
if ($out['proc_cpuinfo_readable']) {
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    $out['nproc_via_cpuinfo'] = $cpuinfo !== false ? substr_count($cpuinfo, 'processor') : null;
}

// ---- exec/shell availability ----
$out['disable_functions_ini'] = ini_get('disable_functions');
$out['exec_exists'] = function_exists('exec');
$out['shell_exec_exists'] = function_exists('shell_exec');
if ($out['shell_exec_exists']) {
    $out['shell_exec_uptime_test'] = @shell_exec('uptime 2>&1');
}

// ---- disk space (pure PHP, no shell) ----
$out['disk_free_space_root'] = @disk_free_space(__DIR__ . '/../../..');
$out['disk_total_space_root'] = @disk_total_space(__DIR__ . '/../../..');

// ---- open_basedir ----
$out['open_basedir'] = ini_get('open_basedir');
$out['php_sapi_name'] = php_sapi_name();
$out['php_version'] = phpversion();

// ---- MySQL introspection via existing PDO connection ----
$db = pw_db();
function pw_diag_try($db, $sql) {
    try {
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return 'ERROR: ' . $e->getMessage();
    }
}

$out['mysql_version'] = pw_diag_try($db, 'SELECT VERSION() AS v');
$out['show_status_threads_connected'] = pw_diag_try($db, "SHOW STATUS LIKE 'Threads_connected'");
$out['show_status_max_used_connections'] = pw_diag_try($db, "SHOW STATUS LIKE 'Max_used_connections'");
$out['show_status_uptime'] = pw_diag_try($db, "SHOW STATUS LIKE 'Uptime'");
$out['show_status_queries'] = pw_diag_try($db, "SHOW STATUS LIKE 'Queries'");
$out['show_status_slow_queries'] = pw_diag_try($db, "SHOW STATUS LIKE 'Slow_queries'");
$out['show_status_aborted_connects'] = pw_diag_try($db, "SHOW STATUS LIKE 'Aborted_connects'");
$out['show_variables_max_connections'] = pw_diag_try($db, "SHOW VARIABLES LIKE 'max_connections'");
$out['show_variables_slow_query_log'] = pw_diag_try($db, "SHOW VARIABLES LIKE 'slow_query_log%'");
$out['show_innodb_buffer_pool'] = pw_diag_try($db, "SHOW STATUS LIKE 'Innodb_buffer_pool_%'");
$out['show_processlist'] = pw_diag_try($db, "SHOW PROCESSLIST");
$out['show_engine_innodb_status'] = pw_diag_try($db, "SHOW ENGINE INNODB STATUS");

// ---- table collation audit (checking for the previously-noted books mismatch) ----
$out['table_collations'] = pw_diag_try($db, "SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");

// ---- per-table sizes (for a possible "largest tables" breakdown) ----
$out['table_sizes'] = pw_diag_try($db, "SELECT TABLE_NAME, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 3) AS size_mb, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC");

pw_json(['ok' => true, 'diag' => $out]);
