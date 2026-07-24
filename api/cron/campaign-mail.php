<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../mail.php';
if (!defined('CRON_SAMPLE_KEY') || !hash_equals(CRON_SAMPLE_KEY, (string)($_GET['key'] ?? ''))) pw_error('Forbidden.', 403);
$db = pw_db();
$campaigns = $db->query("SELECT * FROM mail_campaigns WHERE auto_send_enabled=1 AND status IN ('draft','ready','sending') ORDER BY id ASC")->fetchAll();
foreach ($campaigns as $campaign) {
    if ($campaign['status'] === 'draft') {
        $sql='INSERT IGNORE INTO mail_campaign_recipients (campaign_id,user_id,recipient_email,recipient_name) SELECT ?,id,email,display_name FROM users WHERE newsletter_subscribed=1 AND email<>"" AND (banned_at IS NULL OR (banned_until IS NOT NULL AND banned_until<=NOW())) AND created_at <= NOW() - INTERVAL '.(int)$campaign['registration_age_days'].' DAY'; $params=[(int)$campaign['id']]; if(!empty($campaign['audience_role'])){$sql.=' AND role=?';$params[]=$campaign['audience_role'];}$db->prepare($sql)->execute($params);$count=$db->prepare('SELECT COUNT(*) c FROM mail_campaign_recipients WHERE campaign_id=?');$count->execute([$campaign['id']]);$db->prepare("UPDATE mail_campaigns SET status='ready',recipient_count=? WHERE id=?")->execute([(int)$count->fetch()['c'],$campaign['id']]);
    }
    $rows=$db->prepare("SELECT r.* FROM mail_campaign_recipients r JOIN users u ON u.id=r.user_id WHERE r.campaign_id=? AND r.status='pending' AND u.newsletter_subscribed=1 LIMIT 25");$rows->execute([$campaign['id']]);foreach($rows->fetchAll() as $r){$url='https://thepantheonwars.com/api/newsletter-subscription/unsubscribe.php?u='.$r['user_id'].'&t='.pw_newsletter_unsubscribe_token($r['user_id'],$r['recipient_email']);$out=pw_send_campaign_email($r['recipient_email'],$campaign['subject'],$campaign['html_body'].'<p>Unsubscribe: <a href="'.$url.'">click here</a></p>',$campaign['text_body']."\nUnsubscribe: ".$url);$db->prepare('UPDATE mail_campaign_recipients SET status=?,detail=?,sent_at=NOW() WHERE id=?')->execute([$out['sent']?'accepted':'failed',$out['reason'],$r['id']]);}
}
pw_json(['ok'=>true]);
