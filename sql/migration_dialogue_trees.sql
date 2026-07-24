-- Overlord Dialogue Tree Control
-- Run once in phpMyAdmin against pantheonwars after deploying.
--
-- The JSON document is deliberately scoped one-to-one with an Overlord. It
-- keeps whole trees atomic while the admin editor validates every node and
-- branch before saving; a missing row continues to use the existing result
-- transmission (and Syn's established authored encounter).

CREATE TABLE IF NOT EXISTS overlord_dialogue_trees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  overlord_id INT UNSIGNED NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  tree_json MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_overlord_dialogue_trees_overlord (overlord_id),
  CONSTRAINT fk_overlord_dialogue_trees_overlord FOREIGN KEY (overlord_id) REFERENCES overlords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('dialogues.view', 'View Overlord Dialogue Tree Control', 'Lore Management'),
  ('dialogues.edit', 'Create and edit Overlord dialogue trees', 'Lore Management')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
