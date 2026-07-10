-- Adds a permission gating whether an admin can see member/admin IP
-- addresses (last login IP on the Members list/modal, and the IP column on
-- Recent Activity + the full Audit Log). Run by hand via phpMyAdmin's SQL
-- tab against the `pantheonwars` database.
--
-- No role_permissions seed row is added here on purpose: 'admin' already
-- sees everything via is_superuser, and 'moderator' doesn't have
-- dashboards.view or members.view today so it was never seeing IPs anyway --
-- this is a genuinely new, opt-in permission, not a backfill of existing
-- behavior. Grant it to a role explicitly via the Roles & Permissions admin
-- UI if you want that role to see IP addresses.

INSERT INTO permissions (`key`, label, category) VALUES
  ('dashboards.view_ip_addresses', 'View member & admin IP addresses', 'Dashboards')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
