-- Community controls for Admin Console > System > Site Settings.
-- Run once in phpMyAdmin after deploying the corresponding code.

INSERT INTO app_settings (`key`, value) VALUES
  ('site_registration_enabled', '1'),
  ('site_forum_topics_enabled', '1'),
  ('site_forum_replies_enabled', '1'),
  ('site_direct_messages_enabled', '1'),
  ('site_news_comments_enabled', '1'),
  ('site_reactions_enabled', '1'),
  ('site_community_read_only', '0')
ON DUPLICATE KEY UPDATE `key` = VALUES(`key`);
