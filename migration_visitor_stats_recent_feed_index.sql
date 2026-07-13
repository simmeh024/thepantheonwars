-- The Recent Visits feed orders raw events by timestamp and the page-view ID
-- as a deterministic tie-breaker. Replace the timestamp-only index with an
-- index that matches that exact order, so MariaDB can read the newest rows
-- directly instead of sorting a growing page_views table.
--
-- This also continues to support created_at-only range queries and the raw
-- retention delete, so the standalone idx_created_at index is redundant.

ALTER TABLE page_views
  DROP INDEX idx_created_at,
  ADD INDEX idx_created_at_id (created_at, id);
