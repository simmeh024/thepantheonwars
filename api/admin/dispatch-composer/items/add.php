<?php
/** Attaches an approved dispatch to a Composer draft as reference material. */
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

$dispatchStmt = $db->prepare(
    'SELECT d.id, d.subject FROM dispatch_entries d
     JOIN dispatch_translations dt ON dt.dispatch_id = d.id
     WHERE d.id = ?'
);
$dispatchStmt->execute([$dispatchId]);
$dispatch = $dispatchStmt->fetch();
if (!$dispatch) {
    pw_error('That dispatch does not exist or has no approved translation yet.', 404);
}

$existsStmt = $db->prepare('SELECT id FROM dispatch_composer_items WHERE composer_post_id = ? AND dispatch_id = ?');
$existsStmt->execute([$composerPostId, $dispatchId]);
if ($existsStmt->fetch()) {
    pw_json(['ok' => true]); // already attached -- idempotent
}

$nextOrderStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM dispatch_composer_items WHERE composer_post_id = ?');
$nextOrderStmt->execute([$composerPostId]);
$nextOrder = (int)$nextOrderStmt->fetch()['next_order'];

$db->prepare('INSERT INTO dispatch_composer_items (composer_post_id, dispatch_id, sort_order) VALUES (?, ?, ?)')
    ->execute([$composerPostId, $dispatchId, $nextOrder]);
$db->prepare('UPDATE dispatch_composer_posts SET updated_by = ? WHERE id = ?')->execute([(int)$adminUser['id'], $composerPostId]);

pw_log_admin_activity(
    'dispatch_composer_dispatch_attached',
    'Attached dispatch "' . $dispatch['subject'] . '" to Composer draft #' . $composerPostId . '.',
    $adminUser
);

pw_json(['ok' => true]);
