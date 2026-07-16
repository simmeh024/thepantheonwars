<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$adminUser = pw_require_permission('mail.manage');
$input = pw_input();
pw_require_csrf($input);
$recipient = trim((string)($input['recipient_email'] ?? ''));
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) pw_error('Enter a valid test recipient email address.');
$templateKey = trim((string)($input['template_key'] ?? 'welcome'));
if (!in_array($templateKey, pw_mail_template_keys(), true)) {
    pw_error('Choose a valid mail template to test.');
}

$result = pw_send_template_email($templateKey, $recipient, [
    'recipient_name' => $adminUser['display_name'] ?: $adminUser['username'],
    'recipient_email' => $recipient,
    // Preview messages use harmless destinations and never mint real account
    // credentials, even when the selected template is password_reset.
    'reset_url' => 'https://thepantheonwars.com/password-reset.html',
    'verify_url' => 'https://thepantheonwars.com',
    'ban_reason' => 'This is a delivery and layout preview only. No account action has been taken.',
], ['allow_paused_template' => true]);
if (empty($result['sent'])) {
    $messages = [
        'disabled' => 'Enable transactional delivery before sending a test.',
        'sender_not_configured' => 'Save a valid sender email address before sending a test.',
        'transport_unavailable' => 'This server does not currently expose a mail transport.',
        'template_unavailable' => 'The selected template is unavailable.',
        'transport_rejected' => 'The hosting mail transport rejected the test email.',
    ];
    pw_error($messages[$result['reason'] ?? ''] ?? 'The test email could not be sent.', 409);
}
pw_log_admin_activity('mail_test_sent', 'Sent the ' . $templateKey . ' mail template test to ' . $recipient . '.', $adminUser);
pw_json(['ok' => true]);
