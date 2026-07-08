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
$stmt = $db->prepare('SELECT id, username, email, display_name, role, banned_at, banned_until FROM users WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Member not found.', 404);
}

$displayName = array_key_exists('display_name', $input) ? trim((string)$input['display_name']) : $existing['display_name'];
$email = array_key_exists('email', $input) ? trim((string)$input['email']) : $existing['email'];
$role = array_key_exists('role', $input) ? trim((string)$input['role']) : $existing['role'];
$banned = array_key_exists('banned', $input) ? (bool)$input['banned'] : pw_is_banned($existing);
$banType = array_key_exists('ban_type', $input) ? trim((string)$input['ban_type']) : 'permanent';
$bannedUntilRaw = array_key_exists('banned_until', $input) ? trim((string)$input['banned_until']) : '';

if ($displayName === '' || mb_strlen($displayName) > 50) {
    pw_error('Display name must be between 1 and 50 characters.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
    pw_error('Enter a valid email address.');
}
if (!in_array($role, ['member', 'moderator', 'admin'], true)) {
    pw_error('Not a valid role.');
}
if (!in_array($banType, ['permanent', 'temporary'], true)) {
    $banType = 'permanent';
}

$newBannedUntil = null;
if ($banned && $banType === 'temporary') {
    if ($bannedUntilRaw === '') {
        pw_error('Choose a date and time to auto-unban.');
    }
    $ts = strtotime($bannedUntilRaw);
    if ($ts === false) {
        pw_error('That auto-unban date/time is not valid.');
    }
    if ($ts <= time()) {
        pw_error('The auto-unban time must be in the future.');
    }
    $newBannedUntil = date('Y-m-d H:i:s', $ts);
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

$wasBanned = pw_is_banned($existing);
$bannedAt = $banned ? ($wasBanned ? $existing['banned_at'] : date('Y-m-d H:i:s')) : null;
$bannedUntil = $banned ? $newBannedUntil : null;

$stmt = $db->prepare('UPDATE users SET display_name = ?, email = ?, role = ?, banned_at = ?, banned_until = ? WHERE id = ?');
$stmt->execute([$displayName, $email, $role, $bannedAt, $bannedUntil, $id]);

$targetLabel = $existing['username'];
$roleLabels = ['member' => 'Member', 'moderator' => 'Moderator', 'admin' => 'Admin'];

function pw_ban_description($untilSql) {
    if ($untilSql === null) {
        return 'permanently';
    }
    return 'until ' . date('M j, Y \a\t g:i A', strtotime($untilSql));
}

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
    pw_log_admin_activity(
        'member_banned',
        'Banned the account ' . $targetLabel . ' ' . pw_ban_description($bannedUntil) . '.',
        $adminUser
    );
} elseif ($banned && $wasBanned && $bannedUntil !== $existing['banned_until']) {
    pw_log_admin_activity(
        'member_banned',
        'Updated the ban on ' . $targetLabel . ' -- now banned ' . pw_ban_description($bannedUntil) . '.',
        $adminUser
    );
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
        'banned_until' => $bannedUntil,
    ],
]);
