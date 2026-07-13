<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../dispatch-translation-drafts.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_translations.edit');
$input = pw_input();
pw_require_csrf($input);

$dispatchId = isset($input['dispatch_id']) ? (int)$input['dispatch_id'] : 0;
if ($dispatchId <= 0) {
    pw_error('Missing dispatch id.');
}

$db = pw_db();
try {
    $created = pw_create_dispatch_translation_draft($db, $dispatchId);
} catch (PDOException $e) {
    pw_error('Draft storage is not available yet. Run migration_dispatch_translation_drafts.sql first.', 503);
}

if (!$created) {
    pw_error('This dispatch already has an approved translation or no longer exists.', 409);
}

pw_log_admin_activity('translation_draft_generated', 'Generated a rule-based end-user draft for dispatch #' . $dispatchId . '.', $adminUser);
pw_json(['ok' => true]);
