<?php
require_once __DIR__ . '/../../helpers.php';
pw_require_permission('mail.campaigns.view');
$rows = pw_db()->query('SELECT id, name, subject, status, recipient_count, accepted_count, failed_count, updated_at FROM mail_campaigns ORDER BY updated_at DESC, id DESC')->fetchAll();
pw_json(['ok' => true, 'campaigns' => $rows]);
