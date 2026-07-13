-- Run once in phpMyAdmin before deploying the deterministic Dispatch draft feature.
-- Drafts are intentionally separate from dispatch_translations: only the latter
-- is public, so an automatically formatted draft can never be published until
-- an admin approves it through the Admin Console.
CREATE TABLE dispatch_translation_drafts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispatch_id INT UNSIGNED NOT NULL,
  sha VARCHAR(40) NOT NULL,
  draft TEXT NOT NULL,
  source ENUM('rule_based') NOT NULL DEFAULT 'rule_based',
  draft_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_dispatch_translation_draft (dispatch_id),
  KEY idx_draft_sha (sha),
  CONSTRAINT fk_dispatch_translation_drafts_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
