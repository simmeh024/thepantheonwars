<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

pw_require_admin();
$input = pw_input();
pw_require_csrf($input);

$dispatchId = isset($input['dispatch_id']) ? (int)$input['dispatch_id'] : 0;
if ($dispatchId <= 0) {
    pw_error('Missing dispatch id.');
}

$db = pw_db();
$stmt = $db->prepare('DELETE FROM dispatch_translations WHERE dispatch_id = ?');
$stmt->execute([$dispatchId]);

pw_json(['ok' => true]);
