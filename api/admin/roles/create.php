<?php
// Creates a new custom role (never is_superuser/is_builtin -- those are
// reserved for the 3 seeded roles). Slug is derived from the label rather
// than taken from the client, so there's no way to collide with or spoof a
// reserved slug like 'admin'.
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('roles.manage');

$input = pw_input();
pw_require_csrf($input);

$label = isset($input['label']) ? trim((string)$input['label']) : '';
$color = isset($input['color']) ? trim((string)$input['color']) : '#c7ccd6';
$permissions = isset($input['permissions']) && is_array($input['permissions']) ? $input['permissions'] : [];

if ($label === '' || mb_strlen($label) > 60) {
    pw_error('Role name must be between 1 and 60 characters.');
}
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
    pw_error('Choose a valid color.');
}

$db = pw_db();

$base = strtolower(preg_replace('/[^a-z0-9]+/', '-', $label));
$base = trim($base, '-');
if ($base === '') {
    $base = 'role';
}
$slug = substr($base, 0, 34);
$suffix = 1;
$checkStmt = $db->prepare('SELECT 1 FROM roles WHERE slug = ?');
while (true) {
    $checkStmt->execute([$slug]);
    if (!$checkStmt->fetch()) {
        break;
    }
    $suffix++;
    $slug = substr($base, 0, 34) . '-' . $suffix;
}

$validPerms = array_column($db->query('SELECT `key` FROM permissions')->fetchAll(), 'key');
$permissions = array_values(array_intersect($permissions, $validPerms));

$maxSort = $db->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM roles')->fetch();
$sortOrder = (int)$maxSort['m'] + 1;

$stmt = $db->prepare('INSERT INTO roles (slug, label, color, is_superuser, is_builtin, sort_order) VALUES (?, ?, ?, 0, 0, ?)');
$stmt->execute([$slug, $label, $color, $sortOrder]);

if ($permissions) {
    $insert = $db->prepare('INSERT INTO role_permissions (role_slug, permission_key) VALUES (?, ?)');
    foreach ($permissions as $key) {
        $insert->execute([$slug, $key]);
    }
}

pw_log_admin_activity('role_created', 'Created a new role: "' . $label . '".', $adminUser);

pw_json(['ok' => true, 'role' => ['slug' => $slug, 'label' => $label, 'color' => $color]]);
