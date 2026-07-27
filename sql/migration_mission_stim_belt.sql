-- The stim belt: a small grid of assigned quick slots on the command view.
--
-- Slots are assigned rather than auto-filled from whatever the player happens
-- to hold. Auto-filling would need no table and could never desync -- but the
-- slot count would then only bind on someone already carrying more distinct
-- stims than slots, which is nobody early on, and a limit that never binds is
-- not a limit. Assignment makes the belt a decision from the first stim.
--
-- Two new research effects arrive with this and need no schema change, because
-- game_research_nodes.effect_type is VARCHAR(32) and the valid set is the
-- closed vocabulary in pw_research_effect_types():
--   stim_slots         adds quick slots to the belt below
--   inventory_capacity raises BOTH quartermaster ceilings by the same amount
-- Neither is seeded. A node needs a category, a canvas position, a cost and a
-- rank, all of which are content decisions belonging to whoever owns the
-- lattice layout. They appear in Research Control's effect dropdown once this
-- deploy is live.
--
-- Idempotent: safe to re-run from the top.

CREATE TABLE IF NOT EXISTS game_player_stim_slots (
  user_id INT UNSIGNED NOT NULL,
  -- Position in the grid, zero-based, read left to right. Validated against the
  -- player's current capacity on write, so a slot beyond the belt cannot be
  -- filled by a crafted request.
  slot_index TINYINT UNSIGNED NOT NULL,
  loot_definition_id INT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, slot_index),
  -- One slot per stim type. Quantity is a stack, so a second slot holding the
  -- same stim would show the same number twice and consume from one pool --
  -- assigning an already-slotted stim moves it instead.
  UNIQUE KEY uq_game_player_stim_slot_item (user_id, loot_definition_id),
  CONSTRAINT fk_game_player_stim_slot_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  -- Cascade rather than SET NULL: a slot with no item is simply an absent row,
  -- and a retired loot definition should free its slot rather than hold it.
  CONSTRAINT fk_game_player_stim_slot_item FOREIGN KEY (loot_definition_id) REFERENCES game_loot_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
