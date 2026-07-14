-- Run once in phpMyAdmin after deploying the Dispatch Draft Translator v13.
-- Stores only safe, aggregate GitHub diff metadata: no source code and no
-- file paths. The DDT engine uses it to give reader-facing drafts more
-- grounded context and to recognize their primary product area.
CREATE TABLE IF NOT EXISTS dispatch_diff_context (
  dispatch_id INT UNSIGNED NOT NULL PRIMARY KEY,
  files_changed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  extensions_json VARCHAR(255) NOT NULL DEFAULT '[]',
  areas_json VARCHAR(255) NOT NULL DEFAULT '[]',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dispatch_diff_context_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
