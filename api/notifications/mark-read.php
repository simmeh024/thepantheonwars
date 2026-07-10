<?php
/**
 * Marks either a single notification ({id}) or all of the caller's
 * notifications ({all:true}) as read. Always scoped to the logged-in
 * user's own rows -- there is no way to mark someone else's notifications.
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$db = pw_db();

if (!empty($input['all'])) {
    $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$user['id']]);
} else {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        pw_error('Missing notification id.');
    }
    $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
}

pw_json(['ok' => true]);
