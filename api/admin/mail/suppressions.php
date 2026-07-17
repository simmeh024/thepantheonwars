<?php
/**
 * Read-only view of MailerSend's own suppression lists (hard bounces, spam
 * complaints, unsubscribes) -- MailerSend silently refuses to send to any
 * address on these, which otherwise looks identical to an unexplained
 * "accepted but never arrives" mystery. Never fetched/stored proactively;
 * only live-queried when an admin opens the panel, so it's always current
 * and needs no sync job or local table.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../mail.php';
require_once __DIR__ . '/../../mail/mailersend-client.php';

pw_require_permission('mail.view');

if (!pw_mail_uses_mailersend()) {
    pw_json(['ok' => true, 'available' => false, 'entries' => []]);
}

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$page = max(1, (int)($_GET['page'] ?? 1));

$result = pw_mailersend_suppressions($type, $page);
if ($result === null) {
    pw_json(['ok' => true, 'available' => true, 'entries' => [], 'reason' => 'Could not reach MailerSend, or the token lacks Suppressions: Read access.']);
}

pw_json(['ok' => true, 'available' => true, 'entries' => $result['entries'], 'total' => $result['total']]);
