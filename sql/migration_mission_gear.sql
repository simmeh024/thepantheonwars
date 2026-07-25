-- Crew gear: equipment a crew member carries into an operation.
--
-- Gear is loot, not a parallel system. game_loot_definitions already carries a
-- tier, a world, a drop weight and an enabled flag, and pw_missions_roll_loot()
-- already draws from it with weighted rolls and a Science-driven tier
-- promotion -- so an item with a slot drops through the pipeline that already
-- exists, and the tier that previously meant nothing starts meaning something.
-- An item with no slot stays exactly what it is today: plain salvage.
--
-- Bonuses are the four crew stats and nothing else. Those already drive every
-- downstream effect through pw_missions_crew_effects() -- success, loot draws,
-- tier promotion, XP -- so gear composes with role bonuses, mission affinity
-- and weather with no new effect plumbing anywhere.
--
-- Idempotent: safe to re-run from the top.

-- 1. Gear fields on the existing loot definition ------------------------------
-- SMALLINT rather than UNSIGNED on the four bonuses: a cursed or unbalanced
-- item that trades one stat for another is a reasonable thing to author later,
-- and an unsigned column would make that impossible without a second migration.
ALTER TABLE `game_loot_definitions`
  ADD COLUMN IF NOT EXISTS `slot` VARCHAR(20) NOT NULL DEFAULT '' AFTER `tier`,
  ADD COLUMN IF NOT EXISTS `bonus_strength` SMALLINT NOT NULL DEFAULT 0 AFTER `slot`,
  ADD COLUMN IF NOT EXISTS `bonus_cunning` SMALLINT NOT NULL DEFAULT 0 AFTER `bonus_strength`,
  ADD COLUMN IF NOT EXISTS `bonus_science` SMALLINT NOT NULL DEFAULT 0 AFTER `bonus_cunning`,
  ADD COLUMN IF NOT EXISTS `bonus_charisma` SMALLINT NOT NULL DEFAULT 0 AFTER `bonus_science`,
  ADD COLUMN IF NOT EXISTS `required_level` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `bonus_charisma`,
  ADD COLUMN IF NOT EXISTS `required_role` VARCHAR(40) NOT NULL DEFAULT '' AFTER `required_level`,
  ADD COLUMN IF NOT EXISTS `icon_url` VARCHAR(255) NOT NULL DEFAULT '' AFTER `required_role`;

-- Serves the inventory's slot filter and the admin Gear list, both of which
-- read every enabled item for one slot.
ALTER TABLE `game_loot_definitions`
  ADD KEY IF NOT EXISTS `idx_game_loot_definition_slot` (`slot`, `is_enabled`);

-- 2. What each crew member is wearing -----------------------------------------
-- One row per crew member per slot, enforced by the unique key, so equipping
-- into an occupied slot replaces rather than stacks.
--
-- Quantity is deliberately NOT decremented from game_player_loot when an item
-- is equipped. That table is a quantity ledger with no per-copy identity, so
-- removing a unit would make "how many do I own" ambiguous the moment items can
-- also be spent or sold. Instead the server enforces the invariant that the
-- copies of an item equipped across a player's whole roster never exceed the
-- quantity owned -- one query, checked inside the equip transaction.
CREATE TABLE IF NOT EXISTS game_player_crew_gear (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  player_crew_id BIGINT UNSIGNED NOT NULL,
  slot VARCHAR(20) NOT NULL,
  loot_definition_id INT UNSIGNED NOT NULL,
  equipped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_player_crew_gear_slot (player_crew_id, slot),
  KEY idx_game_player_crew_gear_owned (user_id, loot_definition_id),
  CONSTRAINT fk_game_player_crew_gear_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_crew_gear_crew FOREIGN KEY (player_crew_id) REFERENCES game_player_crew(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_crew_gear_item FOREIGN KEY (loot_definition_id) REFERENCES game_loot_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
