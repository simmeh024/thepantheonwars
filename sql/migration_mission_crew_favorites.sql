-- Mission Crew Favourites
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files. Favourites belong to a player's owned crew row, not the
-- shared crew definition, so one commander's preference never affects another.

ALTER TABLE game_player_crew
  ADD COLUMN IF NOT EXISTS is_favorite TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD INDEX IF NOT EXISTS idx_game_player_crew_favorite (user_id, is_favorite);
