<?php
/**
 * "Recent Visits" card: paginated raw feed of individual page_views rows.
 * IP address is nulled out unless the admin also holds
 * dashboards.view_ip_addresses -- same pattern as
 * api/admin/members/list.php and api/admin/activity-log/list.php -- and
 * even then only a masked form (pw_mask_ip(), e.g. 203.0.xxx.xxx) is sent,
 * never the raw address.
 */
require_once __DIR__ . '/../../helpers.php';

$adminUser = pw_require_permission('analytics.view');
$canViewIp = pw_has_permission($adminUser, 'dashboards.view_ip_addresses');

$db = pw_db();

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if ($perPage <= 0) {
    $perPage = 25;
}
if ($perPage > 100) {
    $perPage = 100;
}

$total = (int)$db->query('SELECT COUNT(*) AS c FROM page_views')->fetch()['c'];
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT pv.path, pv.referrer_host, pv.ip_address, pv.created_at,
            pv.user_id, u.username, u.display_name
     FROM page_views pv
     LEFT JOIN users u ON u.id = pv.user_id
     ORDER BY pv.created_at DESC, pv.id DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$out = array_map(function ($r) use ($canViewIp) {
    return [
        'path' => $r['path'],
        'referrer_host' => $r['referrer_host'],
        'is_member' => $r['user_id'] !== null,
        'member_name' => $r['user_id'] !== null ? ($r['display_name'] ?: $r['username']) : null,
        'ip_address' => $canViewIp ? pw_mask_ip($r['ip_address']) : null,
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
