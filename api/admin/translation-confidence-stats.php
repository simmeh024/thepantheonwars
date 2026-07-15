<?php
/**
 * Current confidence distribution for rule-based Dispatch translations.
 * Kept as a small fallback endpoint for the Admin Home summary card.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/runtime-cache.php';
require_once __DIR__ . '/../dispatch-translation-drafts.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pw_error('Method not allowed.', 405);
}

pw_require_permission('dashboards.view_home');
$db = pw_db();
pw_json(pw_admin_runtime_cache_remember(
    $db,
    'dispatch-translation-confidence-v24',
    300,
    static function () use ($db): array {
        return pw_get_dispatch_translation_confidence_statistics($db);
    }
));
