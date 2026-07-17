<?php
/**
 * Signed MailerSend activity-webhook receiver.
 *
 * pw_send_template_email() only ever knows that MailerSend's API *accepted*
 * a message -- never whether it was actually delivered, opened, bounced, or
 * marked spam. MailerSend can push those outcomes here as they happen
 * (configured as a webhook in the MailerSend dashboard for this domain,
 * events: sent/delivered/opened/clicked/soft_bounced/hard_bounced/
 * spam_complaint/unsubscribed). Each event becomes its own row in the
 * existing append-only mail_delivery_logs trail rather than mutating the
 * original "accepted" row, so Mail Log shows the full lifecycle of a send.
 *
 * Payload shape and signature scheme per MailerSend's webhook docs:
 * https://developers.mailersend.com/api/v1/webhooks.html
 *   Header: Signature: hex(hmac_sha256(raw_request_body, signing_secret))
 *   Body:   { "type": "activity.delivered", "created_at": "...", "data": {
 *               "message_id": "...", "email_id": "...", "email": "recipient@...",
 *               ... } }
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
if (!defined('MAILERSEND_WEBHOOK_SIGNING_SECRET') || MAILERSEND_WEBHOOK_SIGNING_SECRET === '') {
    pw_error('Not found.', 404);
}

$raw = file_get_contents('php://input');
$signature = strtolower(trim((string)($_SERVER['HTTP_SIGNATURE'] ?? '')));
if (!preg_match('/^[a-f0-9]{64}$/', $signature)) pw_error('Unauthorized.', 401);

$expected = hash_hmac('sha256', $raw, MAILERSEND_WEBHOOK_SIGNING_SECRET);
if (!hash_equals($expected, $signature)) pw_error('Unauthorized.', 401);

$payload = json_decode($raw, true);
if (!is_array($payload)) pw_error('Invalid JSON payload.');

// e.g. "activity.hard_bounced" -> "hard_bounced". An unrecognized/future event
// type is still logged verbatim rather than dropped, so nothing silently
// disappears if MailerSend adds a new event later.
$type = trim((string)($payload['type'] ?? ''));
$status = preg_replace('/^activity\./', '', $type);
$status = $status !== '' ? $status : 'unknown';

$data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
$recipientEmail = trim((string)($data['email'] ?? ''));
// message_id is the same identifier returned as the X-Message-Id header at
// send time (pw_mail_send_via_mailersend()); email_id is a separate,
// more granular MailerSend identifier -- both are kept so an admin can
// still correlate the event even if one of the two doesn't line up.
$messageId = trim((string)($data['message_id'] ?? ''));
$emailId = trim((string)($data['email_id'] ?? ''));
$reason = trim((string)($data['reason'] ?? $data['description'] ?? ''));

$detailParts = ['MailerSend webhook: ' . $status . '.'];
if ($reason !== '') $detailParts[] = $reason;
if ($emailId !== '') $detailParts[] = 'email_id ' . $emailId;

pw_mail_log_event('outbound', $status, [
    'recipient_email' => $recipientEmail,
    'provider_message_id' => $messageId !== '' ? $messageId : $emailId,
    'detail' => implode(' ', $detailParts),
]);

pw_json(['ok' => true, 'recorded' => true]);
