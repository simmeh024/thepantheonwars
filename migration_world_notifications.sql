-- migration_world_notifications.sql
-- Adds a "world_available" notification type, broadcast to every member
-- when an admin flips a world's status to "available" in World Control.
-- Run by hand via phpMyAdmin's SQL tab, then folded into sql/schema.sql.

ALTER TABLE notifications
  MODIFY type ENUM('like','mention','quote','report_resolved','world_available') NOT NULL,
  ADD COLUMN world_id INT UNSIGNED NULL AFTER report_id,
  ADD CONSTRAINT fk_notifications_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE;

ALTER TABLE notification_preferences
  ADD COLUMN notif_world_available TINYINT(1) NOT NULL DEFAULT 1;
