-- News publication notifications
-- Run once in phpMyAdmin against rdy3i6my40b0_pantheonwars after deploying.
-- Existing members receive the new preference as enabled by default.

ALTER TABLE notifications
  MODIFY type ENUM('like','mention','quote','report_resolved','world_available','news_published') NOT NULL,
  ADD COLUMN news_slug VARCHAR(120) NULL AFTER world_id;

ALTER TABLE notification_preferences
  ADD COLUMN notif_news_published TINYINT(1) NOT NULL DEFAULT 1 AFTER notif_world_available;
