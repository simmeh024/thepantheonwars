<?php
// Updates a role's color/label/permission set. The slug itself is never
// editable via this endpoint (it's the FK target from users.role, so
// renaming it would require a cascading rewrite -- not worth the risk for a
// purely cosmetic label change). The superuser role ('admin') only accepts a
// color change here -- its permission set can't be edited at all, by design,
// so no combination of checkbox mistakes can lock every admin out.
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
$stmt = $db->prepare('SELECT slug, label, color, is_superuser, is_builtin FROM roles WHERE slug = ?');
$stmt->execute([$slug]);
$role = $stmt->fetch();
if (!$role) {
    pw_error('Role not found.', 404);
}

$color = isset($input['color']) ? trim((string)$input['color']) : $role['color'];
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
    pw_error('Choose a valid color.');
}

$label = $role['label'];
if (!$role['is_builtin'] && isset($input['label'])) {
    $label = trim((string)$input['label']);
    if ($label === '' || mb_strlen($label) > 60) {
        pw_error('Role name must be between 1 and 60 characters.');
    }
}

$db->prepare('UPDATE roles SET label = ?, color = ? WHERE slug = ?')->execute([$label, $color, $slug]);

if (!$role['is_superuser'] && isset($input['permissions']) && is_array($input['permissions'])) {
    $validPerms = array_column($db->query('SELECT `key` FROM permissions')->fetchAll(), 'key');
    $permissions = array_values(array_intersect($input['permissions'], $validPerms));

    $db->prepare('DELETE FROM role_permissions WHERE role_slug = ?')->execute([$slug]);
    if ($permissions) {
        $insert = $db->prepare('INSERT INTO role_permissions (role_slug, permission_key) VALUES (?, ?)');
        foreach ($permissions as $key) {
            $insert->execute([$slug, $key]);
        }
    }
}

pw_log_admin_activity('role_updated', 'Updated the role "' . $label . '".', $adminUser);

pw_json(['ok' => true]);
