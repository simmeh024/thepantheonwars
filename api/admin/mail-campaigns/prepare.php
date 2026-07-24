<?php
require_once __DIR__ . '/../../helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('mail.campaigns.send'); $input = pw_input(); pw_require_csrf($input);
$id = (int)($input['id'] ?? 0); if ($id <= 0) pw_error('Choose a campaign.');
$db = pw_db(); $db->beginTransaction();
try {
    $campaign = $db->prepare("SELECT id, name, status FROM mail_campaigns WHERE id = ? FOR UPDATE"); $campaign->execute([$id]); $row = $campaign->fetch();
    if (!$row || !in_array($row['status'], ['draft','ready','paused'], true)) pw_error('This campaign cannot be prepared.', 409);
    $db->prepare('DELETE FROM mail_campaign_recipients WHERE campaign_id = ? AND status = "pending"')->execute([$id]);
    $rule=$db->prepare('SELECT audience_role,registration_age_days FROM mail_campaigns WHERE id=?');$rule->execute([$id]);$rule=$rule->fetch();
    $sql='INSERT IGNORE INTO mail_campaign_recipients (campaign_id,user_id,recipient_email,recipient_name) SELECT ?,id,email,display_name FROM users WHERE newsletter_subscribed=1 AND email<>"" AND (banned_at IS NULL OR (banned_until IS NOT NULL AND banned_until <= NOW()))';$params=[$id]; if(!empty($rule['audience_role'])){$sql.=' AND role=?';$params[]=$rule['audience_role'];} if($rule['registration_age_days']!==null){$sql.=' AND created_at <= NOW() - INTERVAL '.(int)$rule['registration_age_days'].' DAY';}
    $insert=$db->prepare($sql);$insert->execute($params); $count = $db->prepare('SELECT COUNT(*) AS c FROM mail_campaign_recipients WHERE campaign_id = ?'); $count->execute([$id]); $total=(int)$count->fetch()['c'];
    $db->prepare("UPDATE mail_campaigns SET status='ready', recipient_count=?, updated_by=? WHERE id=?")->execute([$total,$admin['id'],$id]);
    $db->commit(); pw_log_admin_activity('mail_campaign_prepared','Snapshotted '.$total.' opted-in recipients for mail campaign #'.$id.'.',$admin); pw_json(['ok'=>true,'recipients'=>$total]);
} catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
