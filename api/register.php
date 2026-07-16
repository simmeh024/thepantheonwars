<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();
pw_require_csrf($input);

$username = isset($input['username']) ? trim($input['username']) : '';
$email = isset($input['email']) ? trim($input['email']) : '';
$password = isset($input['password']) ? (string)$input['password'] : '';

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
if (pw_password_is_pwned($password)) {
    pw_error('That password has appeared in known data breaches. Please choose a different one.');
}

$db = pw_db();

$stmt = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
$stmt->execute([$username, $email]);
if ($stmt->fetch()) {
    pw_error('That username or email is already taken.', 409);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $db->prepare('INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)');
$stmt->execute([$username, $email, $hash, $username]);
$userId = (int)$db->lastInsertId();

session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
pw_issue_user_session($userId, 'password');
pw_log_activity('session_created', 'Created a password-authenticated session.', $userId, $username);
// Mail delivery is explicitly opt-in in Admin > Mail Settings. A transport
// failure is intentionally ignored here so a new account can never be blocked
// by an email-provider issue.
pw_send_template_email('welcome', $email, ['recipient_name' => $username, 'recipient_email' => $email]);

pw_json(['ok' => true, 'user' => [
    'id' => $userId,
    'username' => $username,
    'display_name' => $username,
]]);
