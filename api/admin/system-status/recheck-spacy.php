<?php
/**
 * Runs the existing local spaCy health probe on demand. This does not restart
 * or alter a Python service: the bridge already launches a short-lived worker
 * for every probe. The shared status snapshots are invalidated so BH-4, Home,
 * and System Status do not keep reporting a previous result.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/status-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dashboards.recheck_spacy');
$input = pw_input();
pw_require_csrf($input);

$db = pw_db();
pw_admin_runtime_cache_forget($db, 'admin-system-signals-v2');
pw_admin_runtime_cache_forget($db, 'admin-system-status-detail-v2');
$spacy = pw_dispatch_spacy_status();

pw_log_admin_activity(
    'spacy_rechecked',
    'Ran an on-demand spaCy health check: ' . ($spacy['label'] ?? 'Unknown') . '.',
    $adminUser
);

pw_json(['ok' => true, 'spacy' => $spacy]);
