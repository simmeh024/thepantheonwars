-- Authored item levels for mission equipment.
--
-- iLvl is deliberately independent from rarity and raw stat bonuses. The
-- editor offers a live suggestion as an authoring aid, but content releases
-- set the stored value by hand so a later release can establish a new ceiling
-- without retuning older rewards. Slotless salvage and stims retain 0 because
-- only the seven worn slots participate in a crew member's average.
--
-- Idempotent: safe to run from the top in phpMyAdmin.

ALTER TABLE `game_loot_definitions`
  ADD COLUMN IF NOT EXISTS `item_level` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `required_role`;

-- Existing wearable items predate iLvl. Give them the smallest usable value
-- rather than silently treating a fully equipped legacy crew as unarmed. An
-- administrator can then set the intended release level in Mission Control.
UPDATE `game_loot_definitions`
SET `item_level` = 1
WHERE `slot` <> '' AND `item_level` = 0;
