-- Per-mission watermark
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files.
--
-- Each mission carries its own emblem, drawn softly inside that mission's card
-- on the Missions page. This is separate from the single page-wide watermark in
-- Mission Control -> Presentation: that one sits behind the whole page, this one
-- belongs to one operation and travels with it onto its active-mission card.
--
-- Both columns default to "no watermark", so nothing changes for an existing
-- mission until an administrator sets one.

ALTER TABLE game_mission_definitions
  ADD COLUMN IF NOT EXISTS watermark_url VARCHAR(255) NOT NULL DEFAULT '' AFTER credit_reward,
  ADD COLUMN IF NOT EXISTS watermark_opacity TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER watermark_url;
