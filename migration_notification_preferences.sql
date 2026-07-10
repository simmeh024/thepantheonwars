-- Adds per-user notification type opt-out flags, backing the new
-- "Notification Settings" tab on profile.html. Run once in phpMyAdmin's
-- SQL tab against the pantheonwars database. A missing row for a user
-- means every notification type stays enabled (see
-- pw_notifications_enabled() in api/helpers.php) -- existing users need no
-- backfill, they simply get a row the first time they save a preference.

CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id INT UNSIGNED PRIMARY KEY,
  notif_like TINYINT(1) NOT NULL DEFAULT 1,
  notif_mention TINYINT(1) NOT NULL DEFAULT 1,
  notif_quote TINYINT(1) NOT NULL DEFAULT 1,
  notif_report_resolved TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
