-- Run once in phpMyAdmin after deploying the member presence feature.
-- Offline is deliberately not a selectable value: it is derived from session
-- activity, while members may choose Online, Away, or Inactive when signed in.
ALTER TABLE users
  ADD COLUMN presence_status ENUM('online', 'away', 'inactive') NOT NULL DEFAULT 'online'
  AFTER last_active_at;
