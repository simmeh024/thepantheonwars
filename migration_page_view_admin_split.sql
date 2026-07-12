-- Adds admin-excluded columns to page_view_daily_stats so the Visitor
-- Statistics admin page can filter superuser (admin/staff) traffic out of
-- the "Visits Over Time" chart by default, with a settings-menu toggle to
-- add it back. Every other visitor-stats endpoint queries the raw
-- page_views table directly and applies the same filter live via
-- pw_admin_view_filter_sql() -- only this chart reads from a permanent
-- daily rollup, since it needs to show trends beyond the 90-day rolling
-- window page_views itself is pruned to.

ALTER TABLE page_view_daily_stats
  ADD COLUMN total_views_excl_admin INT UNSIGNED NOT NULL DEFAULT 0 AFTER guest_views,
  ADD COLUMN unique_visitors_excl_admin INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_views_excl_admin,
  ADD COLUMN member_views_excl_admin INT UNSIGNED NOT NULL DEFAULT 0 AFTER unique_visitors_excl_admin;

-- Best-effort backfill for existing rows: default the excl_admin columns to
-- the unfiltered totals (assume no admin views), then overwrite with exact
-- figures for any stat_date whose raw page_views rows still exist (the
-- 90-day rolling window) -- older rolled-up days can no longer be
-- recomputed exactly since their raw rows are already pruned.
UPDATE page_view_daily_stats
SET total_views_excl_admin = total_views,
    unique_visitors_excl_admin = unique_visitors,
    member_views_excl_admin = member_views;

UPDATE page_view_daily_stats s
JOIN (
  SELECT DATE(pv.created_at) AS stat_date,
         COUNT(*) AS total_views_excl_admin,
         COUNT(DISTINCT pv.visitor_id) AS unique_visitors_excl_admin,
         SUM(CASE WHEN pv.user_id IS NOT NULL THEN 1 ELSE 0 END) AS member_views_excl_admin
  FROM page_views pv
  WHERE pv.user_id IS NULL OR pv.user_id NOT IN (
    SELECT u.id FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r1 ON r1.slug = u.role
    LEFT JOIN roles r2 ON r2.slug = ur.role_slug
    WHERE r1.is_superuser = 1 OR r2.is_superuser = 1
  )
  GROUP BY DATE(pv.created_at)
) x ON x.stat_date = s.stat_date
SET s.total_views_excl_admin = x.total_views_excl_admin,
    s.unique_visitors_excl_admin = x.unique_visitors_excl_admin,
    s.member_views_excl_admin = x.member_views_excl_admin;
