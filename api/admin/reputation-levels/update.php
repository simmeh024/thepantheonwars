<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('reputation.edit');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing level id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id FROM reputation_levels WHERE id = ?');
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    pw_error('Reputation level not found.', 404);
}

$name = isset($input['name']) ? trim($input['name']) : '';
$threshold = isset($input['threshold']) ? (int)$input['threshold'] : -1;
$color = isset($input['color']) ? trim($input['color']) : '';

if ($name === '') {
    pw_error('Give this level a name.');
}
if (mb_strlen($name) > 60) {
    pw_error('That name is too long (60 characters max).');
}
if ($threshold < 0) {
    pw_error('The reputation threshold must be zero or higher.');
}
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
    pw_error('Pick a valid color.');
}

$dupStmt = $db->prepare('SELECT id FROM reputation_levels WHERE threshold = ? AND id != ?');
$dupStmt->execute([$threshold, $id]);
if ($dupStmt->fetch()) {
    pw_error('A level already exists at that reputation threshold.');
}

$stmt = $db->prepare('UPDATE reputation_levels SET name = ?, threshold = ?, color = ? WHERE id = ?');
$stmt->execute([$name, $threshold, $color, $id]);

pw_log_admin_activity('reputation_level_updated', 'Updated reputation level "' . $name . '".', $adminUser);

pw_json(['ok' => true]);
