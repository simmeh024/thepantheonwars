<?php
/**
 * Toggles the logged-in member's own mailing-list subscription flag, backing
 * the checkbox in Profile Settings (below Change Password). Single boolean,
 * same "own account, no other permission needed" trust level as
 * api/presence/update.php.
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$subscribed = !empty($input['subscribed']) ? 1 : 0;

$stmt = pw_db()->prepare('UPDATE users SET newsletter_subscribed = ? WHERE id = ?');
$stmt->execute([$subscribed, $user['id']]);

pw_json(['ok' => true, 'subscribed' => (bool)$subscribed]);
