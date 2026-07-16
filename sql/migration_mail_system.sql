-- Transactional Mail + Templates
-- Run once in phpMyAdmin against rdy3i6my40b0_pantheonwars after deploying.
-- Delivery is disabled by default. Configure a verified sender in Admin > Mail
-- > Mail Settings, then enable delivery only after a deliberate test.

CREATE TABLE IF NOT EXISTS mail_templates (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(40) NOT NULL,
  label VARCHAR(100) NOT NULL,
  description VARCHAR(255) NOT NULL,
  subject VARCHAR(180) NOT NULL,
  html_body MEDIUMTEXT NOT NULL,
  text_body MEDIUMTEXT NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_mail_template_key (template_key),
  CONSTRAINT fk_mail_templates_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('mail.view', 'View Mail settings and templates', 'Mail'),
  ('mail.manage', 'Configure mail delivery and edit templates', 'Mail')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

INSERT INTO app_settings (`key`, value) VALUES
  ('mail_enabled', '0'),
  ('mail_from_name', 'The Pantheon Wars'),
  ('mail_from_email', ''),
  ('mail_reply_to', 'privacy@thepantheonwars.com')
ON DUPLICATE KEY UPDATE `key` = VALUES(`key`);

INSERT INTO mail_templates (template_key, label, description, subject, html_body, text_body, is_enabled) VALUES
(
  'password_reset',
  'Password reset',
  'Sent after a self-service request. The secure link is single-use, expires after 30 minutes, and never contains a password.',
  'Reset your {{site_name}} password',
  '<p>Hello {{recipient_name}},</p><p>We received a request to reset your {{site_name}} password. Use the secure link below to continue:</p><p><a href="{{reset_url}}">Reset your password</a></p><p>If you did not request this, you can safely ignore this email.</p>',
  'Hello {{recipient_name}},\n\nWe received a request to reset your {{site_name}} password.\n\nReset your password: {{reset_url}}\n\nIf you did not request this, you can safely ignore this email.',
  1
),
(
  'welcome',
  'Welcome',
  'Sent when a new member account is created after mail delivery is enabled.',
  'Welcome to {{site_name}}',
  '<p>Welcome, {{recipient_name}}.</p><p>Your account is ready. You can sign in and begin exploring the worlds, books, and community whenever you are ready.</p><p><a href="{{login_url}}">Enter {{site_name}}</a></p>',
  'Welcome, {{recipient_name}}.\n\nYour account is ready. Sign in here: {{login_url}}',
  1
),
(
  'account_banned',
  'Account suspended',
  'Sent after an administrator suspends an account, if delivery is enabled.',
  'Your {{site_name}} account has been suspended',
  '<p>Hello {{recipient_name}},</p><p>Your {{site_name}} account has been suspended.</p><p>{{ban_reason}}</p><p>For questions, reply to this email or contact {{support_email}}.</p>',
  'Hello {{recipient_name}},\n\nYour {{site_name}} account has been suspended.\n\n{{ban_reason}}\n\nFor questions, contact {{support_email}}.',
  1
),
(
  'verify_account',
  'Verify account',
  'Prepared for the account-verification flow when it is enabled.',
  'Verify your {{site_name}} account',
  '<p>Hello {{recipient_name}},</p><p>Please confirm your email address to finish setting up your {{site_name}} account.</p><p><a href="{{verify_url}}">Verify your account</a></p>',
  'Hello {{recipient_name}},\n\nVerify your account here: {{verify_url}}',
  1
)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  description = VALUES(description);
