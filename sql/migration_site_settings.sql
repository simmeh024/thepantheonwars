-- Site Settings (Admin Console > System > Site Settings)
-- Run once in phpMyAdmin against pantheonwars after deploying.
-- Lets an administrator toggle each OAuth sign-in provider on/off
-- independently of whether real provider credentials are configured --
-- e.g. Apple OAuth can stay off until an Apple Developer account is ready,
-- without needing a code deploy to flip it on later.

INSERT INTO permissions (`key`, label, category) VALUES
  ('site_settings.view', 'View Site Settings', 'System'),
  ('site_settings.manage', 'Change Site Settings', 'System')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

INSERT INTO app_settings (`key`, value) VALUES
  ('oauth_google_enabled', '1'),
  ('oauth_apple_enabled', '0')
ON DUPLICATE KEY UPDATE `key` = VALUES(`key`);
