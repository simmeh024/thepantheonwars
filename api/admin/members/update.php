<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

// This one endpoint edits profile fields, role, and ban status together (a
// single Save button in the admin console's member-edit modal) -- but those
// are 3 separate permissions, so we only require login here and check each
// permission below, scoped to which fields actually changed.
$adminUser = pw_require_login();
if (!pw_has_permission($adminUser, 'members.edit')
    && !pw_has_permission($adminUser, 'members.change_role')
    && !pw_has_permission($adminUser, 'members.ban')) {
    pw_error('You do not have permission to do that.', 403);
}

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

$existingOtherRolesStmt = $db->prepare('SELECT role_slug FROM user_roles WHERE user_id = ? ORDER BY role_slug');
$existingOtherRolesStmt->execute([$id]);
$existingOtherRoles = array_column($existingOtherRolesStmt->fetchAll(), 'role_slug');

$displayName = array_key_exists('display_name', $input) ? trim((string)$input['display_name']) : $existing['display_name'];
$email = array_key_exists('email', $input) ? trim((string)$input['email']) : $existing['email'];
$role = array_key_exists('role', $input) ? trim((string)$input['role']) : $existing['role'];
$banned = array_key_exists('banned', $input) ? (bool)$input['banned'] : pw_is_banned($existing);
$banType = array_key_exists('ban_type', $input) ? trim((string)$input['ban_type']) : 'permanent';
$bannedUntilRaw = array_key_exists('banned_until', $input) ? trim((string)$input['banned_until']) : '';

$otherRolesProvided = array_key_exists('other_roles', $input) && is_array($input['other_roles']);
$otherRoles = $existingOtherRoles;
if ($otherRolesProvided) {
    $otherRoles = array_values(array_unique(array_map('strval', $input['other_roles'])));
}

if ($displayName === '' || mb_strlen($displayName) > 50) {
    pw_error('Display name must be between 1 and 50 characters.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
    pw_error('Enter a valid email address.');
}
$roleCheck = $db->prepare('SELECT label FROM roles WHERE slug = ?');
$roleCheck->execute([$role]);
$roleRow = $roleCheck->fetch();
if (!$roleRow) {
    pw_error('Not a valid role.');
}
if (!in_array($banType, ['permanent', 'temporary'], true)) {
    $banType = 'permanent';
}

// Other roles only add permission grants on top of the main role, so
// listing the main role again there would be redundant -- drop it silently
// rather than erroring. Every remaining slug must be a real role.
$otherRoles = array_values(array_diff($otherRoles, [$role]));
if ($otherRoles) {
    $placeholders = implode(',', array_fill(0, count($otherRoles), '?'));
    $validStmt = $db->prepare("SELECT slug FROM roles WHERE slug IN ($placeholders)");
    $validStmt->execute($otherRoles);
    $validSlugs = array_column($validStmt->fetchAll(), 'slug');
    if (count($validSlugs) !== count($otherRoles)) {
        pw_error('One or more selected other roles is not valid.');
    }
}

$profileChanged = ($displayName !== $existing['display_name']) || ($email !== $existing['email']);
$roleChanged = ($role !== $existing['role']);
$banChanged = $banned !== pw_is_banned($existing);
$sortedExistingOtherRoles = $existingOtherRoles;
sort($sortedExistingOtherRoles);
$sortedNewOtherRoles = $otherRoles;
sort($sortedNewOtherRoles);
$otherRolesChanged = $otherRolesProvided && ($sortedNewOtherRoles !== $sortedExistingOtherRoles);

if ($profileChanged && !pw_has_permission($adminUser, 'members.edit')) {
    pw_error('You do not have permission to edit member profiles.', 403);
}
if ($roleChanged && !pw_has_permission($adminUser, 'members.change_role')) {
    pw_error('You do not have permission to change member roles.', 403);
}
if ($otherRolesChanged && !pw_has_permission($adminUser, 'members.change_role')) {
    pw_error('You do not have permission to change member roles.', 403);
}
if ($banChanged && !pw_has_permission($adminUser, 'members.ban')) {
    pw_error('You do not have permission to ban/unban members.', 403);
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

// MySQL evaluates SET assignments from left to right. Evaluate the original
// email before replacing it so an administrator changing the address always
// clears verification, while ordinary profile/role edits retain it.
try {
    $stmt = $db->prepare('UPDATE users SET display_name = ?, email_verified_at = CASE WHEN email = ? THEN email_verified_at ELSE NULL END, email = ?, role = ?, banned_at = ?, banned_until = ? WHERE id = ?');
    $stmt->execute([$displayName, $email, $email, $role, $bannedAt, $bannedUntil, $id]);
} catch (PDOException $e) {
    // Keep existing member management available until the manual migration
    // adds email_verified_at. Do not mask unrelated database errors.
    if ($e->getCode() !== '42S22') {
        throw $e;
    }
    $stmt = $db->prepare('UPDATE users SET display_name = ?, email = ?, role = ?, banned_at = ?, banned_until = ? WHERE id = ?');
    $stmt->execute([$displayName, $email, $role, $bannedAt, $bannedUntil, $id]);
}

if ($banned && !$wasBanned) {
    $revokedSessions = pw_revoke_user_sessions($id, null, 'account_banned');
}

$targetLabel = $existing['username'];
$roleLabels = [];
foreach ($db->query('SELECT slug, label FROM roles') as $r) {
    $roleLabels[$r['slug']] = $r['label'];
}

$addedOtherRoles = [];
$removedOtherRoles = [];
if ($otherRolesChanged) {
    $addedOtherRoles = array_values(array_diff($otherRoles, $existingOtherRoles));
    $removedOtherRoles = array_values(array_diff($existingOtherRoles, $otherRoles));

    if ($removedOtherRoles) {
        $delPlaceholders = implode(',', array_fill(0, count($removedOtherRoles), '?'));
        $delStmt = $db->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_slug IN ($delPlaceholders)");
        $delStmt->execute(array_merge([$id], $removedOtherRoles));
    }
    if ($addedOtherRoles) {
        $insStmt = $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_slug) VALUES (?, ?)');
        foreach ($addedOtherRoles as $slug) {
            $insStmt->execute([$id, $slug]);
        }
    }
}

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
if ($otherRolesChanged) {
    $describeRoles = function ($slugs) use ($roleLabels) {
        return implode(', ', array_map(function ($slug) use ($roleLabels) {
            return isset($roleLabels[$slug]) ? $roleLabels[$slug] : $slug;
        }, $slugs));
    };
    $parts = [];
    if ($addedOtherRoles) {
        $parts[] = 'added ' . $describeRoles($addedOtherRoles);
    }
    if ($removedOtherRoles) {
        $parts[] = 'removed ' . $describeRoles($removedOtherRoles);
    }
    pw_log_admin_activity(
        'member_role_changed',
        'Updated other roles for ' . $targetLabel . ': ' . implode('; ', $parts) . '.',
        $adminUser
    );
}
if ($banned && !$wasBanned) {
    pw_log_admin_activity(
        'member_banned',
        'Banned the account ' . $targetLabel . ' ' . pw_ban_description($bannedUntil) . '.',
        $adminUser
    );
    pw_log_admin_activity('member_sessions_revoked', 'Revoked ' . ($revokedSessions ?? 0) . ' session(s) for banned account ' . $targetLabel . '.', $adminUser);
    // Delivery is optional and never blocks the ban or its immediate session
    // revocation. The template supplies the member-facing explanation.
    pw_send_template_email('account_banned', $email, [
        'recipient_name' => $displayName,
        'recipient_email' => $email,
        'ban_reason' => pw_ban_description($bannedUntil) === 'permanently' ? 'Your account has been suspended permanently.' : 'Your account has been suspended ' . pw_ban_description($bannedUntil) . '.',
    ]);
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
        'other_roles' => $otherRoles,
        'banned' => $banned,
        'banned_until' => $bannedUntil,
    ],
]);
