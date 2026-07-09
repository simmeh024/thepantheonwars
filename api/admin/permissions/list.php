<?php
// Static permission catalog for the Roles & Permissions admin UI's checkbox
// list -- backed by the `permissions` table (seeded once via
// migration_permissions.sql), not hardcoded here, so a future DB-side
// addition doesn't need a code deploy to show up.
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('roles.manage');

$db = pw_db();
$rows = $db->query('SELECT `key`, label, category FROM permissions ORDER BY category, label')->fetchAll();

pw_json(['ok' => true, 'permissions' => $rows]);
