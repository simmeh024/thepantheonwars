<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('mail.logs');

$direction = strtolower(trim((string)($_GET['direction'] ?? 'all')));
if (!in_array($direction, ['all', 'inbound', 'outbound'], true)) {
    $direction = 'all';
}
$limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));

try {
    $sql = 'SELECT id, direction, status, template_key, sender_email, recipient_email, subject, provider_message_id, detail, body_bytes, created_at
            FROM mail_delivery_logs';
    $params = [];
    if ($direction !== 'all') {
        $sql .= ' WHERE direction = ?';
        $params[] = $direction;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
    $stmt = pw_db()->prepare($sql);
    $stmt->execute($params);
    pw_json(['ok' => true, 'entries' => $stmt->fetchAll(), 'direction' => $direction]);
} catch (Throwable $e) {
    pw_error('Mail logs are not configured yet. Run the mail-logs migration first.', 409);
}
