<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_translations.view');
$input = pw_input();
pw_require_csrf($input);

$reportId = isset($input['id']) ? (int)$input['id'] : 0;
if ($reportId <= 0) {
    pw_error('Missing report id.');
}

$db = pw_db();
$stmt = $db->prepare(
    "UPDATE dispatch_quality_reports
     SET status = 'reviewed', reviewed_at = UTC_TIMESTAMP(), reviewed_by_username = ?
     WHERE id = ?"
);
$stmt->execute([$adminUser['username'], $reportId]);

pw_json(['ok' => true]);
