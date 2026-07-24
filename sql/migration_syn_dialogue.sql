-- Syn Dravus's first post-quiz dialogue tree.
--
-- Run once in phpMyAdmin after deploying the accompanying code. Each table
-- preserves a different durable consequence: terminal outcomes prevent repeat
-- rewards, flags drive later encounter gates, and fragments are a small codex
-- collection for future lore surfaces.

CREATE TABLE IF NOT EXISTS user_overlord_dialogue_outcomes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  dialogue_key VARCHAR(50) NOT NULL,
  outcome_key VARCHAR(50) NOT NULL,
  completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_dialogue_outcome (user_id, dialogue_key, outcome_key),
  KEY idx_dialogue_outcomes_user (user_id, completed_at),
  CONSTRAINT fk_dialogue_outcomes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_story_flags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  flag_key VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_story_flag (user_id, flag_key),
  KEY idx_story_flag_key (flag_key),
  CONSTRAINT fk_story_flags_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_codex_fragments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  fragment_key VARCHAR(64) NOT NULL,
  discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_codex_fragment (user_id, fragment_key),
  KEY idx_codex_fragments_user (user_id, discovered_at),
  CONSTRAINT fk_codex_fragments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
