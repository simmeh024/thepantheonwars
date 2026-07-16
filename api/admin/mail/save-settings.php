<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$adminUser = pw_require_permission('mail.manage');
$input = pw_input();
pw_require_csrf($input);

$enabled = !empty($input['enabled']);
$fromName = trim((string)($input['from_name'] ?? ''));
$fromEmail = trim((string)($input['from_email'] ?? ''));
$replyTo = trim((string)($input['reply_to'] ?? ''));
if ($fromName === '' || mb_strlen($fromName) > 100) pw_error('Use a sender name of up to 100 characters.');
if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) pw_error('Use a valid sender email address.');
if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) pw_error('Use a valid reply-to email address.');

$values = [
    'mail_enabled' => $enabled ? '1' : '0',
    'mail_from_name' => $fromName,
    'mail_from_email' => $fromEmail,
    'mail_reply_to' => $replyTo,
];
$stmt = pw_db()->prepare('INSERT INTO app_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP');
foreach ($values as $key => $value) $stmt->execute([$key, $value]);

pw_log_admin_activity('mail_settings_updated', 'Updated transactional mail settings' . ($enabled ? ' and enabled delivery.' : ' while delivery remains disabled.'), $adminUser);
pw_json(['ok' => true, 'settings' => pw_mail_public_settings()]);
