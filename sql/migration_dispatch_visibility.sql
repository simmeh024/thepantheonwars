-- Hide a Development Dispatch from every end-user-facing surface without
-- deleting it. The row, its category, its translation, its reactions and its
-- quality feedback are all preserved -- only public visibility changes.
--
-- Run once in phpMyAdmin (cPanel -> phpMyAdmin -> pantheonwars -> SQL tab)
-- after deploying. Until it is run, api/admin/dispatches/update.php falls back
-- to saving title/category only and the public endpoints behave exactly as
-- they did before, so the deploy order is not load-bearing.

ALTER TABLE dispatch_entries
  ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER url;

-- The public feed filters on this column on every request, and the admin list
-- sorts by committed_at, so keep the lookup cheap as the table grows.
ALTER TABLE dispatch_entries
  ADD INDEX idx_hidden_committed (is_hidden, committed_at);
