<?php
require_once __DIR__ . '/../../helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.',405);
$admin = pw_require_permission('mail.campaigns.manage'); $input=pw_input(); pw_require_csrf($input);
$id=(int)($input['id']??0); $name=trim((string)($input['name']??'')); $subject=trim((string)($input['subject']??'')); $html=trim((string)($input['html_body']??'')); $text=trim((string)($input['text_body']??''));
if ($name===''||$subject===''||$html===''||$text==='') pw_error('Name, subject, HTML, and plain-text content are required.');
if ($id) { $stmt=pw_db()->prepare("UPDATE mail_campaigns SET name=?,subject=?,html_body=?,text_body=?,updated_by=? WHERE id=? AND status IN ('draft','ready','paused')"); $stmt->execute([$name,$subject,$html,$text,$admin['id'],$id]); if(!$stmt->rowCount()) pw_error('This campaign can no longer be edited.',409); }
else { $stmt=pw_db()->prepare('INSERT INTO mail_campaigns (name,subject,html_body,text_body,created_by,updated_by) VALUES (?,?,?,?,?,?)');$stmt->execute([$name,$subject,$html,$text,$admin['id'],$admin['id']]);$id=(int)pw_db()->lastInsertId(); }
pw_log_admin_activity('mail_campaign_saved','Saved mail campaign #'.$id.' ('.$name.').',$admin); pw_json(['ok'=>true,'id'=>$id]);
