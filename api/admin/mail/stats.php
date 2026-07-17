<?php
/**
 * Send-activity summary for the last 30 days, aggregated from our own
 * mail_delivery_logs -- not a live MailerSend API call. Once the webhook
 * receiver (api/mail/mailersend-webhook.php) is recording real
 * delivered/opened/clicked/bounced/spam-complaint events, this table
 * already has everything needed locally; a redundant live Activity API
 * call would just add latency and another external failure mode for no
 * extra accuracy.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('mail.logs');

try {
    $stmt = pw_db()->query(
        "SELECT status, COUNT(*) AS n FROM mail_delivery_logs
         WHERE direction = 'outbound' AND created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
         GROUP BY status"
    );
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int)$row['n'];
    }
    pw_json(['ok' => true, 'days' => 30, 'counts' => $counts]);
} catch (Throwable $e) {
    pw_error('Mail logs are not configured yet. Run the mail-logs migration first.', 409);
}
