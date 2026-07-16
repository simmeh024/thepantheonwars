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
  ('mail.manage', 'Configure mail delivery and edit templates', 'Mail'),
  ('mail.logs', 'View inbound and outbound mail troubleshooting logs', 'Mail')
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
  '<div style="margin:0;padding:36px 16px;background-color:#0b0815;color:#eee8ff;font-family:Arial,Helvetica,sans-serif;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;background-color:#171027;border:1px solid #4c3477;border-radius:14px;overflow:hidden;"><tr><td style="padding:18px 34px;background:linear-gradient(115deg,#2d1b54,#171027);border-bottom:1px solid #62478f;"><span style="display:inline-block;margin-right:10px;color:#f2c96d;font-family:Georgia,serif;font-size:18px;font-weight:bold;letter-spacing:1px;">TPW</span><span style="color:#d9c7ff;font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">A new chronicle begins</span></td></tr><tr><td style="padding:38px 34px 30px;"><p style="margin:0 0 10px;color:#f2c96d;font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">Welcome to the Pantheon</p><h1 style="margin:0 0 18px;color:#fff7e4;font-family:Georgia,Times New Roman,serif;font-size:31px;font-weight:normal;line-height:1.2;">Welcome, {{recipient_name}}.</h1><p style="margin:0 0 16px;color:#d8cceb;font-size:16px;line-height:1.7;">Your account is ready. The worlds, books, and community of {{site_name}} are now open for you to explore at your own pace.</p><p style="margin:0 0 28px;color:#a99abd;font-size:14px;line-height:1.6;">Begin with a story, follow a thread through the lore, or step into the conversations already unfolding in Nexus Veil.</p><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td style="border-radius:6px;background-color:#7044c7;"><a href="{{login_url}}" style="display:inline-block;padding:13px 20px;color:#fff;text-decoration:none;font-size:12px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase;">Enter {{site_name}} &rarr;</a></td></tr></table></td></tr><tr><td style="padding:18px 34px;border-top:1px solid #3d2b60;color:#9687ae;font-size:11px;line-height:1.5;">This transmission was sent because a new account was created for {{recipient_email}}. Keep this message for your records.</td></tr></table></td></tr></table></div>',
  'Welcome, {{recipient_name}}.\n\nYour account is ready. The worlds, books, and community of {{site_name}} are now open for you to explore at your own pace.\n\nEnter {{site_name}}: {{login_url}}\n\nThis message was sent because a new account was created for {{recipient_email}}.',
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
