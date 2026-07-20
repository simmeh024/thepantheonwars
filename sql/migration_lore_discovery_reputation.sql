-- One-time first-visit reputation awards for each World and Overlord record.
-- Run once in phpMyAdmin after deploying the accompanying code.

CREATE TABLE IF NOT EXISTS user_lore_discoveries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  entity_type ENUM('world','overlord') NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lore_discovery_member_record (user_id, entity_type, entity_id),
  KEY idx_lore_discovery_member (user_id, discovered_at),
  CONSTRAINT fk_lore_discovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reputation_reward_rules (`key`, label, base_points, is_enabled) VALUES
  ('lore_discovery', 'Discover a World or Overlord (first visit)', 2, 1)
ON DUPLICATE KEY UPDATE label = VALUES(label);
