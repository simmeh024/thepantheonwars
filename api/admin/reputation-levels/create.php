<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('reputation.edit');

$input = pw_input();
pw_require_csrf($input);

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

$db = pw_db();

$dupStmt = $db->prepare('SELECT id FROM reputation_levels WHERE threshold = ?');
$dupStmt->execute([$threshold]);
if ($dupStmt->fetch()) {
    pw_error('A level already exists at that reputation threshold.');
}

$stmt = $db->prepare('INSERT INTO reputation_levels (name, threshold, color) VALUES (?, ?, ?)');
$stmt->execute([$name, $threshold, $color]);
$levelId = (int)$db->lastInsertId();

pw_log_admin_activity('reputation_level_created', 'Added reputation level "' . $name . '" at ' . $threshold . ' reputation.', $adminUser);

pw_json(['ok' => true, 'id' => $levelId]);
