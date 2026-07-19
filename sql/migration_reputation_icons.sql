-- Overlord resonance icons: a fixed 6-icon set, one per quiz Overlord
-- (matching the existing hardcoded list in quiz.html / api/save-quiz-
-- result.php's $validOverlords -- not admin-manageable, same as that
-- list). Unlocked automatically the first time a user scores a 100%
-- ("Pure Resonance") result with that Overlord; a member then chooses one
-- unlocked icon to show next to their reputation bar. Run once in
-- phpMyAdmin after deploying the accompanying application code.

CREATE TABLE IF NOT EXISTS user_unlocked_icons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  icon_key VARCHAR(40) NOT NULL,
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_unlocked_icons (user_id, icon_key),
  CONSTRAINT fk_user_unlocked_icons_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN selected_icon VARCHAR(40) NULL AFTER reputation;

ALTER TABLE notifications
  MODIFY COLUMN type ENUM('like','mention','quote','report_resolved','world_available','news_published','topic_reply','icon_unlocked') NOT NULL;

ALTER TABLE notification_preferences
  ADD COLUMN notif_icon_unlocked TINYINT(1) NOT NULL DEFAULT 1;
