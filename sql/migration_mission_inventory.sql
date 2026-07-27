-- Inventory limits and stims.
--
-- Two separate changes that share one migration because they share one screen.
--
-- 1. INVENTORY LIMITS. game_player_loot has always been unbounded, so a long
--    campaign turns the quartermaster panel into an infinite scroll and the
--    keep-or-destroy decision the gear system was built around never arrives.
--    Two ceilings are enforced in PHP rather than by a constraint: the cap is a
--    total across rows, which no column constraint can express, and a drop that
--    would exceed it has to be reported to the player as a skipped reward
--    rather than fail the whole mission claim.
--
--    Salvage is counted separately from equipment on purpose. Salvage is
--    research currency -- api/research/unlock.php spends it -- so letting a
--    hoard of gear crowd it out would quietly block the research tree.
--
-- 2. STIMS. A stim is an ordinary game_loot_definitions row with a stim_effect
--    and no slot, so it drops from the loot pool, drops from a loot table and
--    sells in the Market through the pipelines that already exist. It is
--    consumed rather than equipped, which is the one thing gear could not
--    already express -- every existing item is permanent once acquired.
--
-- Idempotent: safe to re-run from the top.

-- 1. Stim fields on the existing loot definition -------------------------------
-- An item is a stim when stim_effect is non-empty. Those items must keep an
-- empty slot: a consumable that could also be worn would be two things at once
-- and the loadout reads by slot.
--
-- stim_value carries points for a fatigue stim and a percentage for the two
-- timed boosts, which is why it is DECIMAL rather than an integer -- the
-- research effects it composes with are percentages to two places.
ALTER TABLE `game_loot_definitions`
  ADD COLUMN IF NOT EXISTS `stim_effect` VARCHAR(24) NOT NULL DEFAULT '' AFTER `icon_url`,
  ADD COLUMN IF NOT EXISTS `stim_value` DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER `stim_effect`,
  ADD COLUMN IF NOT EXISTS `stim_duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `stim_value`;

-- Serves the inventory's stim filter and the Market's stim offers, both of
-- which read every enabled stim.
ALTER TABLE `game_loot_definitions`
  ADD KEY IF NOT EXISTS `idx_game_loot_definition_stim` (`stim_effect`, `is_enabled`);

-- 2. Boosts currently running --------------------------------------------------
-- A timed stim is account-wide, not per crew member: mission speed and loot
-- luck are both resolved once per operation from the player's effect totals,
-- so attaching either to an individual would have no place to apply.
--
-- Rows are never updated, only inserted and pruned. Reads filter on expires_at,
-- so an expired row that has not been pruned yet is already inert.
CREATE TABLE IF NOT EXISTS game_player_stim_effects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  loot_definition_id INT UNSIGNED NOT NULL,
  effect_type VARCHAR(24) NOT NULL,
  effect_value DECIMAL(6,2) NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  KEY idx_game_player_stim_active (user_id, expires_at),
  CONSTRAINT fk_game_player_stim_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_stim_item FOREIGN KEY (loot_definition_id) REFERENCES game_loot_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Fatigue recovery research -------------------------------------------------
-- No schema change is needed and none is made here.
-- game_research_nodes.effect_type is already VARCHAR(32); the valid set is the
-- closed vocabulary in pw_research_effect_types(), which now carries
-- fatigue_recovery. Once this deploy is live it appears in Research Control's
-- effect dropdown and can be authored onto as many nodes as the tree wants.
--
-- Deliberately not seeded. A node needs a category, a canvas position, a cost
-- and a rank, all of which are content decisions belonging to whoever owns the
-- lattice layout -- a seeded node would land on an arbitrary square of someone
-- else's canvas.
--
-- It is the counterpart to the crew_fatigue protocol that shipped with fatigue
-- itself: that one raises the ceiling, this one shortens the wait to refill it.
