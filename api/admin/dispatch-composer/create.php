<?php
/** Creates a new, empty Composer draft. */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_composer.create');
$input = pw_input();
pw_require_csrf($input);

$db = pw_db();
$stmt = $db->prepare(
    'INSERT INTO dispatch_composer_posts (title, created_by, updated_by) VALUES (?, ?, ?)'
);
$stmt->execute(['', (int)$adminUser['id'], (int)$adminUser['id']]);
$id = (int)$db->lastInsertId();

pw_log_admin_activity('dispatch_composer_created', 'Started a new Dispatch Composer draft (#' . $id . ').', $adminUser);

pw_json(['ok' => true, 'id' => $id]);
