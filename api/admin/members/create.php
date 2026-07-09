<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('members.edit');

$input = pw_input();
pw_require_csrf($input);

$username = isset($input['username']) ? trim((string)$input['username']) : '';
$email = isset($input['email']) ? trim((string)$input['email']) : '';
$password = isset($input['password']) ? (string)$input['password'] : '';
$displayName = isset($input['display_name']) ? trim((string)$input['display_name']) : '';
$role = isset($input['role']) ? trim((string)$input['role']) : 'member';

if ($username === '' || $email === '' || $password === '') {
    pw_error('Username, email, and password are all required.');
}
if (!preg_match('/^[A-Za-z0-9_\-]{3,30}$/', $username)) {
    pw_error('Usernames must be 3-30 characters: letters, numbers, underscores, or hyphens only.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    pw_error('That email address doesn\'t look valid.');
}
if (strlen($password) < 8) {
    pw_error('Passwords need to be at least 8 characters.');
}
if ($displayName === '') {
    $displayName = $username;
}
if (mb_strlen($displayName) > 50) {
    pw_error('Display name must be 50 characters or fewer.');
}
$db = pw_db();

$roleStmt = $db->prepare('SELECT label FROM roles WHERE slug = ?');
$roleStmt->execute([$role]);
$roleRow = $roleStmt->fetch();
if (!$roleRow) {
    pw_error('Not a valid role.');
}

$stmt = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
$stmt->execute([$username, $email]);
if ($stmt->fetch()) {
    pw_error('That username or email is already taken.', 409);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $db->prepare('INSERT INTO users (username, email, password_hash, display_name, role) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$username, $email, $hash, $displayName, $role]);
$userId = (int)$db->lastInsertId();

pw_log_admin_activity(
    'member_created',
    'Created a new ' . $roleRow['label'] . ' account: ' . $username . '.',
    $adminUser
);

pw_json([
    'ok' => true,
    'member' => [
        'id' => $userId,
        'username' => $username,
        'email' => $email,
        'display_name' => $displayName,
        'role' => $role,
        'banned' => false,
        'last_login_at' => null,
        'last_login_ip' => null,
        'created_at' => date('Y-m-d H:i:s'),
    ],
]);
