-- Mission Campaign Progress
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files. This flags the mission that ends a world's campaign, so
-- the public Missions page can show a segmented progress bar instead of
-- revealing every locked follow-up operation.
--
-- Every statement is idempotent so a partially applied run can be re-executed
-- from the top. Existing installations are unaffected until an administrator
-- marks a final mission in Mission Control; with no flag set, the progress bar
-- simply does not render.

ALTER TABLE game_mission_definitions
  ADD COLUMN IF NOT EXISTS is_campaign_final TINYINT(1) NOT NULL DEFAULT 0 AFTER unlocks_after_completion_count;

-- Serves the per-world "which mission ends this campaign" lookup that
-- api/missions/overview.php runs on every load.
ALTER TABLE game_mission_definitions
  ADD KEY IF NOT EXISTS idx_game_mission_definition_campaign_final (world_key, is_campaign_final);
