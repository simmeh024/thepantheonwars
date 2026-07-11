-- Manual backup log for the System Status "Last Backup" row. cPanel's own
-- automated account backups are disabled on this hosting account ("Your
-- server administrator or server owner must enable this feature" per the
-- cPanel Backup page, confirmed live) so there's no real automated
-- timestamp to check -- this table instead tracks manually-logged backups
-- (e.g. a phpMyAdmin export), stamped by whichever admin clicks "Log
-- Backup Now" in the System Status card.
--
-- Run by hand via phpMyAdmin's SQL tab against the `pantheonwars` DB.

CREATE TABLE IF NOT EXISTS backup_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note VARCHAR(255) NULL,
  logged_by INT UNSIGNED NULL,
  CONSTRAINT fk_backup_log_user FOREIGN KEY (logged_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
