<?php
require_once __DIR__ . '/../../helpers.php';
pw_require_permission('mail.campaigns.view');
$row = pw_db()->query(
    "SELECT COUNT(*) AS recipients FROM users
     WHERE newsletter_subscribed = 1 AND email <> ''
       AND (banned_at IS NULL OR (banned_until IS NOT NULL AND banned_until <= NOW()))"
)->fetch();
pw_json(['ok' => true, 'recipients' => (int)$row['recipients']]);
