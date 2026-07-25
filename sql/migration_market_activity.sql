-- Market activity feed index
--
-- Run once in phpMyAdmin after sql/migration_market.sql. The global Market
-- acquisition feed asks for the newest purchases across all players, so this
-- index keeps that small public read independent of the audit trail's size.

ALTER TABLE game_market_purchases
  ADD INDEX IF NOT EXISTS idx_game_market_purchase_recent (purchased_at, id);
