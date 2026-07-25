-- Mission Successions
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files. Existing missions remain immediately available; set a
-- predecessor and completion count in Mission Control to create a progression.

ALTER TABLE game_mission_definitions
  ADD COLUMN unlocks_after_mission_id INT UNSIGNED NULL AFTER sort_order,
  ADD COLUMN unlocks_after_completion_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER unlocks_after_mission_id,
  ADD KEY idx_game_mission_definition_successor (unlocks_after_mission_id);

ALTER TABLE game_mission_definitions
  ADD CONSTRAINT fk_game_mission_definition_successor
  FOREIGN KEY (unlocks_after_mission_id) REFERENCES game_mission_definitions(id)
  ON DELETE SET NULL;
