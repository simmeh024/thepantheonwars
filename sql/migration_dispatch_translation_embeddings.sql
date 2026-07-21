-- Sentence-transformer embedding cache for approved Dispatch translations.
-- One row per published dispatch_translations row. The embedding vector is
-- computed once by the local embedding service (see docs/dispatch-embeddings.md)
-- and reused for every future draft's semantic-similarity lookup, so only the
-- incoming commit ever needs to be encoded at draft-generation time -- this
-- table is the "only the new commit needs encoding" cache.
--
-- model/translation_hash exist so a changed embedding model or an edited
-- translation is detected and recomputed rather than silently compared
-- against a stale or incompatible vector.
CREATE TABLE IF NOT EXISTS dispatch_translation_embeddings (
  dispatch_id INT UNSIGNED NOT NULL PRIMARY KEY,
  model VARCHAR(64) NOT NULL,
  translation_hash CHAR(64) NOT NULL,
  embedding_json TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dispatch_translation_embeddings_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
