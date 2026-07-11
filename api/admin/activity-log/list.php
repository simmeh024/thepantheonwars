<?php
require_once __DIR__ . '/../../helpers.php';

$adminUser = pw_require_permission('dashboards.view_audit_log');
$canViewIp = pw_has_permission($adminUser, 'dashboards.view_ip_addresses');

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if ($perPage <= 0) {
    $perPage = 25;
}
if ($perPage > 200) {
    $perPage = 200;
}

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
$username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
if (mb_strlen($username) > 100) {
    $username = mb_substr($username, 0, 100);
}

$db = pw_db();

$conditions = [];
$whereParams = [];
if ($action !== '') {
    $conditions[] = 'action = :action';
    $whereParams[':action'] = $action;
}
if ($username !== '') {
    $conditions[] = 'username LIKE :username';
    $whereParams[':username'] = '%' . $username . '%';
}
$whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$countStmt = $db->prepare("SELECT COUNT(*) AS c FROM admin_activity_log $whereSql");
$countStmt->execute($whereParams);
$total = (int)$countStmt->fetch()['c'];
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT id, username, action, description, ip_address, created_at
     FROM admin_activity_log
     $whereSql
     ORDER BY created_at DESC, id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($whereParams as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$out = array_map(function ($r) use ($canViewIp) {
    return [
        'id' => (int)$r['id'],
        'username' => $r['username'],
        'action' => $r['action'],
        'description' => $r['description'],
        'ip_address' => $canViewIp ? $r['ip_address'] : null,
        'created_at' => $r['created_at'],
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
