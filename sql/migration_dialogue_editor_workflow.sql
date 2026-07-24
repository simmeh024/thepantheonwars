-- Dialogue editor workflow: drafts, publish history, and member-owned
-- branch state. Run once in phpMyAdmin against the pantheonwars database
-- after deploying the matching PHP/JS/CSS files.

ALTER TABLE overlord_dialogue_trees
  ADD COLUMN IF NOT EXISTS draft_is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER tree_json,
  ADD COLUMN IF NOT EXISTS published_tree_json MEDIUMTEXT NULL AFTER tree_json,
  ADD COLUMN IF NOT EXISTS published_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER published_tree_json,
  ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER published_version;

-- Existing live custom trees become version 1, preserving public behaviour
-- exactly while later editor saves remain private drafts until Publish.
UPDATE overlord_dialogue_trees
SET draft_is_enabled = is_enabled,
    published_tree_json = tree_json,
    published_version = CASE WHEN published_version < 1 THEN 1 ELSE published_version END,
    published_at = COALESCE(published_at, updated_at)
WHERE published_tree_json IS NULL;

CREATE TABLE IF NOT EXISTS overlord_dialogue_tree_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  overlord_id INT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  tree_json MEDIUMTEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dialogue_tree_version (overlord_id, version_number),
  KEY idx_dialogue_tree_versions_overlord (overlord_id, created_at),
  CONSTRAINT fk_dialogue_tree_versions_overlord FOREIGN KEY (overlord_id) REFERENCES overlords(id) ON DELETE CASCADE,
  CONSTRAINT fk_dialogue_tree_versions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO overlord_dialogue_tree_versions (overlord_id, version_number, tree_json, created_at)
SELECT overlord_id, published_version, published_tree_json, COALESCE(published_at, updated_at)
FROM overlord_dialogue_trees
WHERE published_tree_json IS NOT NULL AND published_version > 0;

CREATE TABLE IF NOT EXISTS user_overlord_dialogue_state (
  user_id INT UNSIGNED NOT NULL,
  overlord_id INT UNSIGNED NOT NULL,
  state_json MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, overlord_id),
  CONSTRAINT fk_user_dialogue_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_dialogue_state_overlord FOREIGN KEY (overlord_id) REFERENCES overlords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_overlord_dialogue_effects (
  user_id INT UNSIGNED NOT NULL,
  overlord_id INT UNSIGNED NOT NULL,
  choice_id VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, overlord_id, choice_id),
  CONSTRAINT fk_user_dialogue_effects_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_dialogue_effects_overlord FOREIGN KEY (overlord_id) REFERENCES overlords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
