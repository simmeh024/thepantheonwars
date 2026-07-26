-- Mission Crew Stat Cache Repair
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files. This repairs player-crew rows that were created after
-- the first stat backfill and therefore inherited the column defaults despite
-- already having a valid level.
--
-- Mirrors pw_missions_stats_for_level(): every level gives one Cunning and two
-- points in the role's primary stat, capped at 50. Equipment is intentionally
-- not stored here; it is layered on dynamically from game_player_crew_gear.

UPDATE game_player_crew pc
JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
SET pc.strength = CASE WHEN c.role = 'Vanguard' THEN LEAST(50, pc.level * 2) ELSE 0 END,
    pc.science  = CASE WHEN c.role = 'Engineer' THEN LEAST(50, pc.level * 2) ELSE 0 END,
    pc.charisma = CASE WHEN c.role = 'Pathfinder' THEN LEAST(50, pc.level * 2) ELSE 0 END,
    pc.cunning  = LEAST(50, pc.level);
