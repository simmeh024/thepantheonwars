<?php
/** Detaches a dispatch from a Composer draft. */
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../composer-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_composer.edit');
$input = pw_input();
pw_require_csrf($input);

$composerPostId = isset($input['composer_post_id']) ? (int)$input['composer_post_id'] : 0;
$dispatchId = isset($input['dispatch_id']) ? (int)$input['dispatch_id'] : 0;
if ($composerPostId <= 0 || $dispatchId <= 0) {
    pw_error('Missing Composer post or dispatch id.');
}

$db = pw_db();
pw_composer_require_editable_post($db, $composerPostId);

$subjectStmt = $db->prepare('SELECT subject FROM dispatch_entries WHERE id = ?');
$subjectStmt->execute([$dispatchId]);
$subject = $subjectStmt->fetchColumn();

$db->prepare('DELETE FROM dispatch_composer_items WHERE composer_post_id = ? AND dispatch_id = ?')
    ->execute([$composerPostId, $dispatchId]);
$db->prepare('UPDATE dispatch_composer_posts SET updated_by = ? WHERE id = ?')->execute([(int)$adminUser['id'], $composerPostId]);

pw_log_admin_activity(
    'dispatch_composer_dispatch_removed',
    'Removed dispatch "' . ($subject !== false ? $subject : ('#' . $dispatchId)) . '" from Composer draft #' . $composerPostId . '.',
    $adminUser
);

pw_json(['ok' => true]);
