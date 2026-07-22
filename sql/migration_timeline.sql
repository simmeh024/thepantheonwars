-- migration_timeline.sql
-- Lore Timeline: timeline_events powering the public timeline.html chronicle
-- (Lore Management > Timeline Control). Flat entity, same list/modal/reorder
-- pattern as Known Figures Control (see migration_known_figures.sql).
--
-- Two things here are deliberate and worth keeping:
--
-- 1. `date_label` is a STRING, not a DATE. In-world time ("Cycle 4.207", "The
--    Long Silence") has no real calendar, so nothing can sort or format it as
--    a date. `sort_order` is the authoritative ordering along the bar.
--
-- 2. `required_level_id` gates an event behind a reputation LEVEL rather than a
--    raw point total, so the existing Reputation Levels admin stays the single
--    place tiers are defined. NULL means always visible. ON DELETE SET NULL is
--    important: removing a reputation level must UNLOCK its events, never
--    orphan them or silently hide lore behind a level that no longer exists.
--
-- Run by hand via phpMyAdmin's SQL tab, then fold into sql/schema.sql per
-- project convention.

CREATE TABLE IF NOT EXISTS timeline_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  title VARCHAR(150) NOT NULL,
  era_label VARCHAR(100) NOT NULL DEFAULT '',
  date_label VARCHAR(100) NOT NULL DEFAULT '',
  summary VARCHAR(400) NOT NULL DEFAULT '',
  body TEXT NULL,
  image_url VARCHAR(255) NOT NULL DEFAULT '',
  accent_color VARCHAR(20) NOT NULL DEFAULT '#a279ec',
  required_level_id INT UNSIGNED NULL DEFAULT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_timeline_published_order (is_published, sort_order),
  CONSTRAINT fk_timeline_required_level FOREIGN KEY (required_level_id)
    REFERENCES reputation_levels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Discovering a timeline event awards reputation through the same one-time
-- first-visit path already used for Worlds and Overlords, so it reuses the
-- existing `lore_discovery` reward rule and needs no new rule or points value.
-- Adding to the enum is additive; existing rows are untouched.
ALTER TABLE user_lore_discoveries
  MODIFY COLUMN entity_type ENUM('world','overlord','timeline_event') NOT NULL;

INSERT INTO permissions (`key`, label, category) VALUES
  ('timeline.view', 'View Timeline Control', 'Lore Management'),
  ('timeline.edit', 'Create/edit/reorder timeline events', 'Lore Management'),
  ('timeline.delete', 'Delete timeline events', 'Lore Management')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
