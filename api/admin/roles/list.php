<?php
// Read-only role list: slug/label/color/flags + how many members currently
// hold each role. Gated only by login (not roles.manage) because this also
// backs the Members section's role <select> (list filter + edit modal),
// which any staff member with members.* permissions needs to populate --
// nothing here is more sensitive than the role badges already shown
// throughout the site.
require_once __DIR__ . '/../../helpers.php';

pw_require_login();

$db = pw_db();
$rows = $db->query(
    'SELECT r.slug, r.label, r.color, r.is_superuser, r.is_builtin,
            (SELECT COUNT(*) FROM users u WHERE u.role = r.slug) AS user_count
     FROM roles r
     ORDER BY r.sort_order, r.label'
)->fetchAll();

$out = array_map(function ($r) {
    return [
        'slug' => $r['slug'],
        'label' => $r['label'],
        'color' => $r['color'],
        'is_superuser' => (bool)$r['is_superuser'],
        'is_builtin' => (bool)$r['is_builtin'],
        'user_count' => (int)$r['user_count'],
    ];
}, $rows);

pw_json(['ok' => true, 'roles' => $out]);
