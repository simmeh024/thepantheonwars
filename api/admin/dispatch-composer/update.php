<?php
/**
 * Saves a Composer draft. Deliberately cannot publish or archive a post --
 * status here is restricted to draft/ready only, so a plain save request
 * (including a replayed/forged one) can never smuggle through
 * {"status":"published"}. Use publish.php and archive.php for those
 * transitions; both are separately permissioned.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/composer-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_composer.edit');
$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing Composer post id.');
}

$db = pw_db();
$existing = pw_composer_require_editable_post($db, $id);

$status = isset($input['status']) ? trim((string)$input['status']) : $existing['status'];
if (!in_array($status, ['draft', 'ready'], true)) {
    pw_error('Invalid status for a save request.');
}

$data = pw_composer_input($input);
$slug = pw_composer_unique_slug($db, $data['title'], $id);

$stmt = $db->prepare(
    'UPDATE dispatch_composer_posts
     SET title = ?, slug = ?, excerpt = ?, body = ?, featured_image_url = ?, status = ?, updated_by = ?
     WHERE id = ?'
);
$stmt->execute([
    $data['title'], $slug, $data['excerpt'], $data['body'], $data['featured_image_url'],
    $status, (int)$adminUser['id'], $id,
]);

// Deliberate status changes are logged; a plain re-save while still in the
// same status is not, so ordinary autosave/typing cannot flood the log.
if ($status !== $existing['status']) {
    pw_log_admin_activity(
        'dispatch_composer.marked_ready',
        'Marked Composer draft "' . ($data['title'] !== '' ? $data['title'] : '(untitled)') . '" as ' . $status . '.',
        $adminUser
    );
} else {
    pw_log_admin_activity(
        'dispatch_composer.saved',
        'Saved Composer draft "' . ($data['title'] !== '' ? $data['title'] : '(untitled)') . '".',
        $adminUser
    );
}

pw_json(['ok' => true]);
