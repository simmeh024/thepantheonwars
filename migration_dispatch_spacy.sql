-- Optional source marker for locally spaCy-enriched Dispatch draft records.
-- Run once in phpMyAdmin after deploying the spaCy integration. The application
-- still works without a configured Python environment; this only records which
-- deterministic enrichment path produced a draft.
ALTER TABLE dispatch_translation_drafts
  MODIFY source ENUM('rule_based', 'rule_based_spacy') NOT NULL DEFAULT 'rule_based';
