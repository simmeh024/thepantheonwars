-- Maintenance Mode (Admin Console > System > Site Settings)
-- Run once in phpMyAdmin against pantheonwars after deploying.
-- Reuses the site_settings.view/site_settings.manage permissions already
-- seeded by migration_site_settings.sql -- no new permission rows needed.

INSERT INTO app_settings (`key`, value) VALUES
  ('maintenance_mode_enabled', '0'),
  ('maintenance_message', '')
ON DUPLICATE KEY UPDATE `key` = VALUES(`key`);
