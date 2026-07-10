<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('members.view');

$db = pw_db();

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if ($perPage <= 0) {
    $perPage = 25;
}
if ($perPage > 200) {
    $perPage = 200;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($q) > 200) {
    $q = substr($q, 0, 200);
}

$role = isset($_GET['role']) ? trim($_GET['role']) : 'all';
if ($role !== 'all') {
    $roleCheck = $db->prepare('SELECT 1 FROM roles WHERE slug = ?');
    $roleCheck->execute([$role]);
    if (!$roleCheck->fetch()) {
        $role = 'all';
    }
}

$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
if (!in_array($status, ['all', 'banned'], true)) {
    $status = 'all';
}

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(username LIKE :q1 OR display_name LIKE :q2 OR email LIKE :q3)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}
if ($role !== 'all') {
    $where[] = 'role = :role';
    $params[':role'] = $role;
}
if ($status === 'banned') {
    $where[] = 'banned_at IS NOT NULL AND (banned_until IS NULL OR banned_until > NOW())';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $db->prepare("SELECT COUNT(*) AS c FROM users $whereSql");
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$total = (int)$countStmt->fetch()['c'];

$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT id, username, email, display_name, role, created_at, last_login_at, last_login_ip, banned_at, banned_until
     FROM users
     $whereSql
     ORDER BY created_at DESC, id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

// Grouped separately (not a JOIN) so a member with zero "other roles" still
// comes back as a single row -- same pattern as roles/list.php's
// permissions-by-role lookup. Read-only, no permission beyond members.view
// needed since it's just which roles a member holds, same sensitivity as
// the single `role` column already returned here.
$otherRolesByUser = [];
foreach ($db->query('SELECT user_id, role_slug FROM user_roles') as $row) {
    $otherRolesByUser[$row['user_id']][] = $row['role_slug'];
}

$out = array_map(function ($r) use ($otherRolesByUser) {
    return [
        'id' => (int)$r['id'],
        'username' => $r['username'],
        'email' => $r['email'],
        'display_name' => $r['display_name'],
        'role' => $r['role'],
        'other_roles' => isset($otherRolesByUser[$r['id']]) ? $otherRolesByUser[$r['id']] : [],
        'created_at' => $r['created_at'],
        'last_login_at' => $r['last_login_at'],
        'last_login_ip' => $r['last_login_ip'],
        'banned' => pw_is_banned($r),
        'banned_at' => $r['banned_at'],
        'banned_until' => $r['banned_until'],
    ];
}, $rows);

pw_json([
    'ok' => true,
    'entries' => $out,
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'total_pages' => $totalPages,
]);
