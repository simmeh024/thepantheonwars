-- Adds country tracking to page_views for the new "Traffic by Country" card
-- and the per-visit country tag on the Recent Visits list. Country is
-- resolved server-side per IP via a free ip-api.com lookup
-- (pw_resolve_country() in api/helpers.php), cached in the new
-- ip_country_cache table so a repeat visitor's IP is only looked up once.

ALTER TABLE page_views
  ADD COLUMN country_code CHAR(2) NULL AFTER ip_address,
  ADD COLUMN country_name VARCHAR(100) NULL AFTER country_code;

CREATE TABLE IF NOT EXISTS ip_country_cache (
  ip_address VARCHAR(64) PRIMARY KEY,
  country_code CHAR(2) NULL,
  country_name VARCHAR(100) NULL,
  resolved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
