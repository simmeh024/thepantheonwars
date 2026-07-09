<?php
// Full detail for a single role (used to populate the Roles & Permissions
// edit modal), including which permission keys it currently has.
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('roles.manage');

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
if ($slug === '') {
    pw_error('Missing role slug.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT slug, label, color, is_superuser, is_builtin FROM roles WHERE slug = ?');
$stmt->execute([$slug]);
$role = $stmt->fetch();
if (!$role) {
    pw_error('Role not found.', 404);
}

$permStmt = $db->prepare('SELECT permission_key FROM role_permissions WHERE role_slug = ?');
$permStmt->execute([$slug]);
$permissions = array_column($permStmt->fetchAll(), 'permission_key');

pw_json([
    'ok' => true,
    'role' => [
        'slug' => $role['slug'],
        'label' => $role['label'],
        'color' => $role['color'],
        'is_superuser' => (bool)$role['is_superuser'],
        'is_builtin' => (bool)$role['is_builtin'],
        'permissions' => $permissions,
    ],
]);
