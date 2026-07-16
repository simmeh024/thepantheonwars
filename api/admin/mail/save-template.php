<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$adminUser = pw_require_permission('mail.manage');
$input = pw_input();
pw_require_csrf($input);
$key = trim((string)($input['template_key'] ?? ''));
$subject = trim((string)($input['subject'] ?? ''));
$html = trim((string)($input['html_body'] ?? ''));
$text = trim((string)($input['text_body'] ?? ''));
if (!in_array($key, pw_mail_template_keys(), true)) pw_error('Unknown mail template.');
if ($subject === '' || mb_strlen($subject) > 180) pw_error('Use a subject of up to 180 characters.');
if ($html === '' || mb_strlen($html) > 20000) pw_error('Use an HTML message of up to 20,000 characters.');
if ($text === '' || mb_strlen($text) > 20000) pw_error('Use a plain-text message of up to 20,000 characters.');

try {
    $stmt = pw_db()->prepare('UPDATE mail_templates SET subject = ?, html_body = ?, text_body = ?, is_enabled = ?, updated_by = ? WHERE template_key = ?');
    $stmt->execute([$subject, $html, $text, !empty($input['is_enabled']) ? 1 : 0, (int)$adminUser['id'], $key]);
} catch (Throwable $e) {
    pw_error('Mail templates are not configured yet. Run the mail-system migration first.', 409);
}
if ($stmt->rowCount() === 0 && !pw_mail_template($key)) pw_error('That mail template is not configured yet. Run the mail-system migration first.', 409);
pw_log_admin_activity('mail_template_updated', 'Updated the ' . str_replace('_', ' ', $key) . ' mail template.', $adminUser);
pw_json(['ok' => true]);
