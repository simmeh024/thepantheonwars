<?php
require_once __DIR__ . '/../../helpers.php';
pw_require_permission('mail.campaigns.view');
$id = (int)($_GET['id'] ?? 0);
$stmt = pw_db()->prepare('SELECT * FROM mail_campaigns WHERE id = ?'); $stmt->execute([$id]); $row = $stmt->fetch();
if (!$row) pw_error('Campaign not found.', 404);
pw_json(['ok' => true, 'campaign' => $row]);
