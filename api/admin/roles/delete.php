<?php
// Deletes a custom role. Builtin roles (member/moderator/admin) can never be
// deleted. A role currently held by any member can't be deleted either --
// the admin must reassign those members to a different role first, same
// "can't delete while referenced" guard used elsewhere in this codebase
// (e.g. books can't dangle a referenced image).
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('roles.manage');

$input = pw_input();
pw_require_csrf($input);

$slug = isset($input['slug']) ? trim((string)$input['slug']) : '';
if ($slug === '') {
    pw_error('Missing role slug.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT slug, label, is_builtin FROM roles WHERE slug = ?');
$stmt->execute([$slug]);
$role = $stmt->fetch();
if (!$role) {
    pw_error('Role not found.', 404);
}
if ($role['is_builtin']) {
    pw_error('Built-in roles can\'t be deleted.', 403);
}

$countStmt = $db->prepare('SELECT COUNT(*) AS c FROM users WHERE role = ?');
$countStmt->execute([$slug]);
if ((int)$countStmt->fetch()['c'] > 0) {
    pw_error('Reassign the members currently holding this role before deleting it.', 409);
}

$db->prepare('DELETE FROM roles WHERE slug = ?')->execute([$slug]);

pw_log_admin_activity('role_deleted', 'Deleted the role "' . $role['label'] . '".', $adminUser);

pw_json(['ok' => true]);
