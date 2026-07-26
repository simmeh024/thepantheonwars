-- Final Research Category + Mission Research Locks
-- Run once in phpMyAdmin after sql/migration_research_categories.sql and the
-- base Research / Missions migrations. This seeds the six standard branches,
-- seals the Final Neoh Protocol until every non-final protocol is online, and
-- makes the Mission Management research-lock checkbox enforceable.

ALTER TABLE game_research_categories
  ADD COLUMN requires_all_other_unlocked TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order;

INSERT INTO game_research_categories
  (name, slug, description, sort_order, requires_all_other_unlocked)
VALUES
  ('Mobility Protocols', 'mobility-protocols', 'Traversal, timing and expedition movement improvements.', 10, 0),
  ('Systems & Hacking', 'systems-hacking', 'Signal control, secure access and classified operation tools.', 20, 0),
  ('Recovery & Survival', 'recovery-survival', 'Recovery resilience, salvage handling and field survival.', 30, 0),
  ('Logistics & Economy', 'logistics-economy', 'Market routes, credits and supply-chain efficiencies.', 40, 0),
  ('Crew & Command', 'crew-command', 'Crew performance, command authority and expedition leadership.', 50, 0),
  ('Final Neoh Protocol', 'final-neoh-protocol', 'The sealed final protocol, revealed only when every other branch is online.', 999, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  sort_order = VALUES(sort_order),
  requires_all_other_unlocked = VALUES(requires_all_other_unlocked);

ALTER TABLE game_mission_definitions
  ADD COLUMN requires_research_unlock TINYINT(1) NOT NULL DEFAULT 0 AFTER is_enabled;

-- Preserve the gates already authored through existing Secret mission access
-- protocols before the Mission Management checkbox became available.
UPDATE game_mission_definitions mission
JOIN game_research_nodes node ON node.target_mission_definition_id = mission.id
SET mission.requires_research_unlock = 1
WHERE node.effect_type = 'secret_mission';
