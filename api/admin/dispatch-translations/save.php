<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

pw_require_admin();
$input = pw_input();
pw_require_csrf($input);

$dispatchId = isset($input['dispatch_id']) ? (int)$input['dispatch_id'] : 0;
$translation = isset($input['translation']) ? trim($input['translation']) : '';

if ($dispatchId <= 0) {
    pw_error('Missing dispatch id.');
}
if ($translation === '') {
    pw_error('Translation text can\'t be empty.');
}
if (strlen($translation) > 5000) {
    pw_error('Translation is too long (5000 characters max).');
}

$db = pw_db();

$stmt = $db->prepare('SELECT sha FROM dispatch_entries WHERE id = ?');
$stmt->execute([$dispatchId]);
$dispatch = $stmt->fetch();
if (!$dispatch) {
    pw_error('That dispatch no longer exists.', 404);
}

$stmt = $db->prepare(
    'INSERT INTO dispatch_translations (dispatch_id, sha, translation)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE sha = VALUES(sha), translation = VALUES(translation)'
);
$stmt->execute([$dispatchId, $dispatch['sha'], $translation]);

pw_json(['ok' => true]);
