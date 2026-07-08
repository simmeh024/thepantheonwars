<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_admin();

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing member id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, username, email, display_name, role, banned_at FROM users WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Member not found.', 404);
}

$displayName = array_key_exists('display_name', $input) ? trim((string)$input['display_name']) : $existing['display_name'];
$email = array_key_exists('email', $input) ? trim((string)$input['email']) : $existing['email'];
$role = array_key_exists('role', $input) ? trim((string)$input['role']) : $existing['role'];
$banned = array_key_exists('banned', $input) ? (bool)$input['banned'] : ($existing['banned_at'] !== null);

if ($displayName === '' || mb_strlen($displayName) > 50) {
    pw_error('Display name must be between 1 and 50 characters.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
    pw_error('Enter a valid email address.');
}
if (!in_array($role, ['member', 'moderator', 'admin'], true)) {
    pw_error('Not a valid role.');
}

if ((int)$existing['id'] === (int)$adminUser['id']) {
    if ($banned) {
        pw_error('You can\'t ban your own account.');
    }
    if ($role !== 'admin') {
        pw_error('You can\'t change your own role.');
    }
}

$dupStmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
$dupStmt->execute([$email, $id]);
if ($dupStmt->fetch()) {
    pw_error('That email address is already in use by another account.');
}

$wasBanned = $existing['banned_at'] !== null;
$bannedAt = $banned ? ($wasBanned ? $existing['banned_at'] : date('Y-m-d H:i:s')) : null;

$stmt = $db->prepare('UPDATE users SET display_name = ?, email = ?, role = ?, banned_at = ? WHERE id = ?');
$stmt->execute([$displayName, $email, $role, $bannedAt, $id]);

$targetLabel = $existing['username'];
$roleLabels = ['member' => 'Member', 'moderator' => 'Moderator', 'admin' => 'Admin'];

if ($displayName !== $existing['display_name']) {
    pw_log_admin_activity(
        'member_updated',
        'Changed display name for ' . $targetLabel . ' from "' . $existing['display_name'] . '" to "' . $displayName . '".',
        $adminUser
    );
}
if ($email !== $existing['email']) {
    pw_log_admin_activity(
        'member_updated',
        'Changed email for ' . $targetLabel . ' from ' . $existing['email'] . ' to ' . $email . '.',
        $adminUser
    );
}
if ($role !== $existing['role']) {
    pw_log_admin_activity(
        'member_role_changed',
        'Changed role for ' . $targetLabel . ' from ' . $roleLabels[$existing['role']] . ' to ' . $roleLabels[$role] . '.',
        $adminUser
    );
}
if ($banned && !$wasBanned) {
    pw_log_admin_activity('member_banned', 'Banned the account ' . $targetLabel . '.', $adminUser);
}
if (!$banned && $wasBanned) {
    pw_log_admin_activity('member_unbanned', 'Unbanned the account ' . $targetLabel . '.', $adminUser);
}

pw_json([
    'ok' => true,
    'member' => [
        'id' => (int)$existing['id'],
        'username' => $existing['username'],
        'email' => $email,
        'display_name' => $displayName,
        'role' => $role,
        'banned' => $banned,
    ],
]);
