<?php
/**
 * Current confidence distribution for rule-based Dispatch translations.
 * Kept as a small fallback endpoint for the Admin Home summary card.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../dispatch-translation-drafts.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pw_error('Method not allowed.', 405);
}

pw_require_permission('dashboards.view_home');
pw_json(pw_get_dispatch_translation_confidence_statistics(pw_db()));
