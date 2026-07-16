-- Mail troubleshooting logs + permission
-- Run once in phpMyAdmin after deploying. This migration records delivery and
-- signed inbound metadata only; it deliberately never stores mail bodies.

CREATE TABLE IF NOT EXISTS mail_delivery_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  direction ENUM('inbound', 'outbound') NOT NULL,
  status VARCHAR(32) NOT NULL,
  template_key VARCHAR(40) NULL,
  sender_email VARCHAR(255) NULL,
  recipient_email VARCHAR(255) NULL,
  subject VARCHAR(255) NULL,
  provider_message_id VARCHAR(255) NULL,
  detail VARCHAR(255) NULL,
  body_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mail_log_direction_created (direction, created_at),
  KEY idx_mail_log_status_created (status, created_at),
  KEY idx_mail_log_recipient_created (recipient_email, created_at),
  KEY idx_mail_log_provider_message (provider_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('mail.logs', 'View inbound and outbound mail troubleshooting logs', 'Mail')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
