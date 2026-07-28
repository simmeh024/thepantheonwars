-- Salvage Sweep sector conditions
--
-- Run once in phpMyAdmin after sql/migration_salvage_sweep.sql. Existing
-- sectors and active runs start as `clear`, preserving their prior behaviour.
-- New runs copy the selected condition onto their own row at launch.

ALTER TABLE game_sweep_tiers
  ADD COLUMN IF NOT EXISTS condition_key VARCHAR(40) NOT NULL DEFAULT 'clear';

ALTER TABLE game_player_sweep_runs
  ADD COLUMN IF NOT EXISTS condition_key VARCHAR(40) NOT NULL DEFAULT 'clear';
