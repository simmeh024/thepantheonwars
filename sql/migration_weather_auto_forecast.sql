-- Opt-in "auto-generate" toggle for a world's Today and Tomorrow weather.
--
-- Today (current_condition/current_temp_c) and Tomorrow (tomorrow_condition/
-- tomorrow_temp_c) are authored fields -- an admin's exact story beat stays
-- exact until they change it. Days 3-5 have always been generated from the
-- condition pool and the world's own forecast_min_c/max_c range. These two
-- new columns let an admin opt either of the first two days into that same
-- generated path instead, independently of each other.
--
-- Off (0) by default, so nothing changes for any world until an admin
-- switches one on. Every PHP read path treats a missing column as "off" via
-- !empty(), so this migration is not deploy-order sensitive -- it is safe to
-- run before or after the accompanying code deploy.
--
-- Run once in phpMyAdmin. Idempotent: safe to re-run.

ALTER TABLE world_weather_profiles
  ADD COLUMN IF NOT EXISTS current_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER current_temp_c,
  ADD COLUMN IF NOT EXISTS tomorrow_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER tomorrow_temp_c;
