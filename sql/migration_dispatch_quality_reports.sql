-- Weekly self-tuning maintenance pass for Dispatch Translation quality.
-- Analyzes the accumulated Good/Bad feedback (dispatch_translation_feedback)
-- and edit-distance log (dispatch_translation_edit_events) over the past
-- week and stores a human-readable summary: overall stats, a per-tag
-- breakdown, a confidence-evidence breakdown, and clusters of semantically
-- similar Bad-rated translations worth a new dictionary entry. Advisory
-- only -- nothing here is ever auto-applied to the translator's rules;
-- an admin reads it and decides what (if anything) to change in code.
CREATE TABLE IF NOT EXISTS dispatch_quality_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  window_start DATE NOT NULL,
  window_end DATE NOT NULL,
  summary_json TEXT NOT NULL,
  status ENUM('unread', 'reviewed') NOT NULL DEFAULT 'unread',
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME DEFAULT NULL,
  reviewed_by_username VARCHAR(80) DEFAULT NULL,
  KEY idx_generated_at (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
