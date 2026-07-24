-- Mail Campaigns (Admin Console > Mail > Mail Campaigns)
-- Run once in phpMyAdmin after deploying.
CREATE TABLE IF NOT EXISTS mail_campaigns (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, subject VARCHAR(180) NOT NULL,
 html_body MEDIUMTEXT NOT NULL, text_body MEDIUMTEXT NOT NULL, audience_role VARCHAR(40) NULL,
 registration_age_days INT UNSIGNED NULL, auto_send_enabled TINYINT(1) NOT NULL DEFAULT 0,
 status ENUM('draft','ready','sending','sent','paused','failed') NOT NULL DEFAULT 'draft', recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
 accepted_count INT UNSIGNED NOT NULL DEFAULT 0, failed_count INT UNSIGNED NOT NULL DEFAULT 0, created_by INT UNSIGNED NOT NULL, updated_by INT UNSIGNED NOT NULL, sent_by INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, sent_at DATETIME NULL,
 KEY idx_mail_campaign_status_updated (status,updated_at), CONSTRAINT fk_mail_campaign_created_by FOREIGN KEY(created_by) REFERENCES users(id), CONSTRAINT fk_mail_campaign_updated_by FOREIGN KEY(updated_by) REFERENCES users(id), CONSTRAINT fk_mail_campaign_sent_by FOREIGN KEY(sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mail_campaign_recipients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,campaign_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NULL,recipient_email VARCHAR(255) NOT NULL,recipient_name VARCHAR(100) NOT NULL,status ENUM('pending','accepted','failed','skipped','unsubscribed') NOT NULL DEFAULT 'pending',detail VARCHAR(255) NULL,sent_at DATETIME NULL,
 UNIQUE KEY uq_campaign_recipient_email(campaign_id,recipient_email),KEY idx_campaign_recipient_status(campaign_id,status),CONSTRAINT fk_campaign_recipient_campaign FOREIGN KEY(campaign_id) REFERENCES mail_campaigns(id) ON DELETE CASCADE,CONSTRAINT fk_campaign_recipient_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO permissions (`key`,label,category) VALUES ('mail.campaigns.view','View Mail Campaigns','Mail'),('mail.campaigns.manage','Create and edit Mail Campaigns','Mail'),('mail.campaigns.send','Send Mail Campaigns','Mail') ON DUPLICATE KEY UPDATE label=VALUES(label),category=VALUES(category);
