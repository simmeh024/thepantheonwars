<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$adminUser = pw_require_permission('mail.manage');
$input = pw_input();
pw_require_csrf($input);
$recipient = trim((string)($input['recipient_email'] ?? ''));
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) pw_error('Enter a valid test recipient email address.');

$result = pw_send_template_email('welcome', $recipient, [
    'recipient_name' => $adminUser['display_name'] ?: $adminUser['username'],
    'recipient_email' => $recipient,
]);
if (empty($result['sent'])) {
    $messages = [
        'disabled' => 'Enable transactional delivery before sending a test.',
        'sender_not_configured' => 'Save a valid sender email address before sending a test.',
        'transport_unavailable' => 'This server does not currently expose a mail transport.',
        'template_unavailable' => 'The Welcome template is unavailable or paused.',
        'transport_rejected' => 'The hosting mail transport rejected the test email.',
    ];
    pw_error($messages[$result['reason'] ?? ''] ?? 'The test email could not be sent.', 409);
}
pw_log_admin_activity('mail_test_sent', 'Sent a Mail Settings test to ' . $recipient . '.', $adminUser);
pw_json(['ok' => true]);
