<?php
/**
 * Signed inbound-mail metadata receiver.
 *
 * A mail provider or cPanel mail pipe can forward parsed mail data here. PHP's
 * native mail() transport cannot read a mailbox itself. Only operational
 * metadata and body length are recorded; the message body is never persisted.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
if (!defined('MAIL_INBOUND_WEBHOOK_SECRET') || MAIL_INBOUND_WEBHOOK_SECRET === '') {
    pw_error('Not found.', 404);
}

$raw = file_get_contents('php://input');
$signature = trim((string)($_SERVER['HTTP_X_PW_MAIL_SIGNATURE'] ?? ''));
$signature = preg_replace('/^sha256=/i', '', $signature);
if (!preg_match('/^[a-f0-9]{64}$/i', $signature)) pw_error('Unauthorized.', 401);

$expected = hash_hmac('sha256', $raw, MAIL_INBOUND_WEBHOOK_SECRET);
if (!hash_equals($expected, strtolower($signature))) pw_error('Unauthorized.', 401);

$payload = json_decode($raw, true);
if (!is_array($payload)) pw_error('Invalid JSON payload.');

$from = trim((string)($payload['from'] ?? ''));
$to = trim((string)($payload['to'] ?? ''));
$subject = trim((string)($payload['subject'] ?? ''));
$messageId = trim((string)($payload['message_id'] ?? $payload['provider_message_id'] ?? ''));
$body = (string)($payload['text'] ?? $payload['body'] ?? '');
$validAddresses = filter_var($from, FILTER_VALIDATE_EMAIL) && filter_var($to, FILTER_VALIDATE_EMAIL);

pw_mail_log_event('inbound', $validAddresses ? 'received' : 'rejected', [
    'sender_email' => $from,
    'recipient_email' => $to,
    'subject' => $subject,
    'provider_message_id' => $messageId,
    'detail' => $validAddresses ? 'Authenticated inbound webhook received.' : 'Authenticated webhook omitted a valid sender or recipient.',
    'body_bytes' => strlen($body),
]);

if (!$validAddresses) pw_error('A valid sender and recipient are required.');
pw_json(['ok' => true, 'recorded' => true]);
