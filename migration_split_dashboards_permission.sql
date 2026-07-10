-- Splits the bundled 'dashboards.view' permission into three separate
-- permissions -- Home dashboard, System Status, and Audit Log -- so a
-- custom role can be granted access to just one of these admin sections
-- instead of all three at once. Visitor Statistics already has its own
-- permission (analytics.view, added by migration_visitor_stats.sql) and is
-- unaffected by this change -- the old bundled permission's label just
-- hadn't been updated after that earlier split. Run by hand via
-- phpMyAdmin's SQL tab against the `pantheonwars` database.

INSERT INTO permissions (`key`, label, category) VALUES
  ('dashboards.view_home', 'View Home dashboard', 'Dashboards'),
  ('dashboards.view_system_status', 'View System Status', 'Dashboards'),
  ('dashboards.view_audit_log', 'View Audit Log', 'Dashboards')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

-- Every role that currently has the old bundled permission keeps exactly
-- the same access after the split (granted all three new permissions).
INSERT INTO role_permissions (role_slug, permission_key)
SELECT role_slug, 'dashboards.view_home' FROM role_permissions WHERE permission_key = 'dashboards.view'
ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug);
INSERT INTO role_permissions (role_slug, permission_key)
SELECT role_slug, 'dashboards.view_system_status' FROM role_permissions WHERE permission_key = 'dashboards.view'
ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug);
INSERT INTO role_permissions (role_slug, permission_key)
SELECT role_slug, 'dashboards.view_audit_log' FROM role_permissions WHERE permission_key = 'dashboards.view'
ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug);

-- Remove the old bundled permission -- cascades to any remaining
-- role_permissions rows referencing it via fk_role_permissions_permission
-- ON DELETE CASCADE, now that they've been replaced by the three above.
DELETE FROM permissions WHERE `key` = 'dashboards.view';
