-- Dispatch auto-categorization confidence + human-override tracking
-- Run once in phpMyAdmin against the pantheonwars DB after deploying.
--
-- The MODIFY below is defensive: sql/schema.sql had documented
-- dispatch_entries.tag as ENUM('feature','fix','update') for a long time,
-- but pw_dispatch_valid_tags() and the admin category-edit endpoint have
-- validated against a real 9-value set (feature, improvement, fix,
-- performance, ui_ux, lore, infrastructure, refactor, experimental) for as
-- long as "category_edited" has been a working, audited admin action. This
-- statement is a no-op if the live column already matches (documentation
-- catching up to reality, same class of drift already fixed once this
-- session for the books/comment_reactions tables); it only actually widens
-- anything if the live column had genuinely been stuck at 3 values.
ALTER TABLE dispatch_entries
  MODIFY COLUMN tag ENUM('feature','improvement','fix','performance','ui_ux','lore','infrastructure','refactor','experimental') NOT NULL DEFAULT 'feature';

ALTER TABLE dispatch_entries
  ADD COLUMN category_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER tag,
  ADD COLUMN category_source ENUM('auto','manual') NOT NULL DEFAULT 'auto' AFTER category_confidence;

-- One row per admin correction, so keyword-list/weight tuning later can be
-- evidence-based ("which auto-guesses keep getting overridden, and to
-- what") instead of another round of guessing. Never mutated after insert.
CREATE TABLE IF NOT EXISTS dispatch_category_overrides (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispatch_id INT UNSIGNED NOT NULL,
  previous_tag VARCHAR(20) NOT NULL,
  previous_confidence TINYINT UNSIGNED NOT NULL,
  previous_source ENUM('auto','manual') NOT NULL,
  new_tag VARCHAR(20) NOT NULL,
  changed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_category_overrides_dispatch (dispatch_id),
  CONSTRAINT fk_category_overrides_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_category_overrides_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
