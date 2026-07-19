<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();
pw_require_csrf($input);
$user = pw_require_login();

$iconKey = array_key_exists('icon_key', $input) ? $input['icon_key'] : null;
$iconKey = $iconKey === null || $iconKey === '' ? null : trim((string)$iconKey);

if ($iconKey !== null) {
    if (!in_array($iconKey, pw_overlord_icon_keys(), true)) {
        pw_error('Unknown icon.', 422);
    }
    $stmt = pw_db()->prepare('SELECT id FROM user_unlocked_icons WHERE user_id = ? AND icon_key = ?');
    $stmt->execute([$user['id'], $iconKey]);
    if (!$stmt->fetch()) {
        pw_error('You have not unlocked that icon yet.', 403);
    }
}

pw_db()->prepare('UPDATE users SET selected_icon = ? WHERE id = ?')->execute([$iconKey, $user['id']]);

pw_json(['ok' => true, 'selected_icon' => $iconKey]);
