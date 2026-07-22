-- Member Warning System (+ optional accompanying mute)
-- Run once in phpMyAdmin against pantheonwars after deploying.

-- One row per issued warning. status='revoked' keeps the row (audit trail
-- preserved) rather than deleting it; only warnings.delete permanently
-- removes a row via api/admin/warnings/delete.php.
CREATE TABLE IF NOT EXISTS member_warnings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  reason TEXT NOT NULL,
  severity ENUM('minor','moderate','severe') NOT NULL DEFAULT 'minor',
  -- 'manual' = issued from the admin Warnings/Members module with no
  -- specific post attached; the other three record which piece of content
  -- the small per-post Warn icon was clicked from.
  source_type ENUM('manual','topic','comment','news_comment') NOT NULL DEFAULT 'manual',
  source_id INT UNSIGNED NULL,
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  issued_by_user_id INT UNSIGNED NULL,
  issued_by_username VARCHAR(50) NOT NULL,
  revoked_by_user_id INT UNSIGNED NULL,
  revoked_by_username VARCHAR(50) NULL,
  revoke_reason TEXT NULL,
  revoked_at DATETIME NULL,
  -- Set only when this warning was accompanied by a mute; purely a display
  -- record of what was applied at issue time -- live enforcement always
  -- reads users.muted_until, never this column.
  mute_minutes INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_member_warnings_user_status (user_id, status),
  KEY idx_member_warnings_created (created_at),
  CONSTRAINT fk_member_warnings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('warnings.view', 'View member warnings', 'Community'),
  ('warnings.manage', 'Issue and revoke member warnings', 'Community'),
  ('warnings.delete', 'Permanently delete member warnings', 'Community')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

-- Notification sent to the warned member (reason + severity, never the
-- issuer's identity -- staff-only visibility of who issued it). Opt-out
-- default-enabled, matching every other notification type's convention.
ALTER TABLE notifications MODIFY type ENUM('like','mention','quote','report_resolved','world_available','news_published','topic_reply','icon_unlocked','direct_message','new_device_login','warning_issued') NOT NULL;
ALTER TABLE notification_preferences ADD COLUMN notif_warning_issued TINYINT(1) NOT NULL DEFAULT 1;

-- Mute state, deliberately mirroring banned_at/banned_until's simplicity so
-- every authenticated request's already-loaded $user row can check it with
-- no extra query. No "permanent" option here (unlike bans) -- only the five
-- fixed durations offered in the Issue Warning UI -- so muted_until alone
-- fully describes the state: NULL or in the past means not muted.
ALTER TABLE users
  ADD COLUMN muted_until DATETIME DEFAULT NULL AFTER banned_until,
  ADD COLUMN mute_reason VARCHAR(255) DEFAULT NULL AFTER muted_until;
