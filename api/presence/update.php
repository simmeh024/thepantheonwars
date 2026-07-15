<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();
pw_require_csrf($input);
$user = pw_require_login();
$status = strtolower(trim((string)($input['status'] ?? '')));

if (!in_array($status, ['online', 'away', 'inactive'], true)) {
    pw_error('Choose Online, Away, or Inactive.', 422);
}

pw_db()->prepare('UPDATE users SET presence_status = ?, last_active_at = NOW() WHERE id = ?')
    ->execute([$status, (int)$user['id']]);

pw_json(['ok' => true, 'presence_status' => $status]);
