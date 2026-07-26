-- One-off crew progression reset.
-- Run once in phpMyAdmin (cPanel -> phpMyAdmin -> `pantheonwars` -> SQL tab).
--
-- This is a DATA reset, not a schema migration: it returns every player's crew
-- to level 1 with zero experience, so the exponential XP curve introduced in
-- 899bffd applies to every career from its start rather than only to the levels
-- earned after it shipped.
--
-- XP must be zeroed alongside the level. Level is re-derived from the stored XP
-- total on the next mission claim (pw_missions_level_for_xp), so clearing the
-- level while leaving the XP would simply re-promote everyone on their next
-- payout and change nothing.
--
-- The four stat columns are the level-derived cache that claim.php maintains,
-- so they are rewritten here to their level-1 values rather than left holding
-- the allocations of levels the crew member no longer has. Those values come
-- from pw_missions_stats_for_level(): two points per level into the role's
-- primary stat, one into Cunning. Equipment bonuses are applied at read time
-- from the gear tables and are deliberately untouched.
--
-- Crew currently out on a mission are safe to reset: the run completes and pays
-- out normally, and the claim recomputes their level from the new XP total.

UPDATE game_player_crew pc
JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
SET pc.level    = 1,
    pc.xp       = 0,
    pc.strength = CASE WHEN c.role = 'Vanguard'   THEN 2 ELSE 0 END,
    pc.charisma = CASE WHEN c.role = 'Pathfinder' THEN 2 ELSE 0 END,
    pc.science  = CASE WHEN c.role = 'Engineer'   THEN 2 ELSE 0 END,
    pc.cunning  = 1;

-- Verification: every row should read level 1 / 0 XP, and each role should show
-- 2 in its primary stat and 1 in Cunning.
-- SELECT c.role, pc.level, pc.xp, pc.strength, pc.cunning, pc.science, pc.charisma, COUNT(*) AS crew
-- FROM game_player_crew pc
-- JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
-- GROUP BY c.role, pc.level, pc.xp, pc.strength, pc.cunning, pc.science, pc.charisma;
