<?php
/** Saves a private writing note on one attached dispatch. Never published. */
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
$note = isset($input['admin_note']) ? trim((string)$input['admin_note']) : '';
if ($composerPostId <= 0 || $dispatchId <= 0) {
    pw_error('Missing Composer post or dispatch id.');
}
if (mb_strlen($note) > 2000) {
    pw_error('The note is too long (2,000 characters max).');
}

$db = pw_db();
pw_composer_require_editable_post($db, $composerPostId);

$stmt = $db->prepare('UPDATE dispatch_composer_items SET admin_note = ? WHERE composer_post_id = ? AND dispatch_id = ?');
$stmt->execute([$note !== '' ? $note : null, $composerPostId, $dispatchId]);
if ($stmt->rowCount() === 0) {
    pw_error('That dispatch is not attached to this draft.', 404);
}
$db->prepare('UPDATE dispatch_composer_posts SET updated_by = ? WHERE id = ?')->execute([(int)$adminUser['id'], $composerPostId]);

pw_json(['ok' => true]);
