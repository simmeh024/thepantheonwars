-- Dispatch Translation quality feedback: an explicit Good/Bad rating an admin
-- can leave on any published translation, plus an automatic log of how much
-- an approved translation differs from whatever the engine originally
-- suggested (a rule-based draft, or nothing at all for a from-scratch typed
-- translation). Neither signal changes any existing translation, confidence
-- score, or auto-publication decision -- this is purely observational data
-- to inform future dictionary/template tuning.

CREATE TABLE IF NOT EXISTS dispatch_translation_feedback (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispatch_id INT UNSIGNED NOT NULL,
  rating ENUM('good', 'bad') NOT NULL,
  rated_by_user_id INT UNSIGNED NOT NULL,
  rated_by_username VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_dispatch_rater (dispatch_id, rated_by_user_id),
  KEY idx_dispatch_id (dispatch_id),
  CONSTRAINT fk_dispatch_translation_feedback_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_dispatch_translation_feedback_user
    FOREIGN KEY (rated_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per publish/edit event. similarity_pct is computed with PHP's
-- built-in similar_text() against whatever text existed immediately before
-- this event (the engine's own rule-based draft, the previously published
-- translation, or NULL when there was nothing to compare against -- e.g. a
-- translation typed entirely from scratch with no prior draft).
CREATE TABLE IF NOT EXISTS dispatch_translation_edit_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispatch_id INT UNSIGNED NOT NULL,
  event ENUM('auto_published', 'manual_save') NOT NULL,
  similarity_pct DECIMAL(5,2) DEFAULT NULL,
  previous_length INT UNSIGNED NOT NULL DEFAULT 0,
  new_length INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dispatch_id (dispatch_id),
  CONSTRAINT fk_dispatch_translation_edit_events_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
