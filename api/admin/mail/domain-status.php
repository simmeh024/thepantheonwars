<?php
/**
 * Reports MailerSend's own verification status for the domain of the
 * currently configured sender email, so an admin doesn't need to check the
 * MailerSend dashboard separately -- and so Mail Settings can warn if the
 * sender email doesn't match a domain MailerSend actually verified.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../mail.php';
require_once __DIR__ . '/../../mail/mailersend-client.php';

pw_require_permission('mail.view');

if (!pw_mail_uses_mailersend()) {
    pw_json(['ok' => true, 'available' => false]);
}

$settings = pw_mail_settings();
$fromEmail = trim((string)$settings['from_email']);
$domainName = strpos($fromEmail, '@') !== false ? substr($fromEmail, strpos($fromEmail, '@') + 1) : '';

if ($domainName === '') {
    pw_json(['ok' => true, 'available' => true, 'domain' => null, 'reason' => 'No sender email is configured yet.']);
}

$status = pw_mailersend_domain_status($domainName);
if ($status === null) {
    pw_json(['ok' => true, 'available' => true, 'domain' => null, 'reason' => 'Domain "' . $domainName . '" was not found in this MailerSend account, or the token lacks Domains: Read access.']);
}

pw_json(['ok' => true, 'available' => true, 'domain' => $status]);
