-- Index work from a query-load review. No data changes, no new tables.
--
-- Idempotent: safe to re-run from the top.
--
-- NOTE on ordering, per the standing warning in CLAUDE.md: InnoDB refuses to
-- drop the only index whose leftmost column serves a foreign key. The DROP at
-- the bottom is safe specifically because the UNIQUE KEY named in its comment
-- already leads with that column and is not being touched -- but if that ever
-- changes, add the replacement before dropping anything.

-- 1. The mission settling sweep ------------------------------------------------
-- api/cron/complete-missions.php calls pw_missions_settle_due_runs() with no
-- user, so its filter is:
--
--     WHERE status = 'active' AND completes_at <= UTC_TIMESTAMP()
--
-- The only index on this table is (user_id, status, completes_at). Its leftmost
-- column is user_id, which that query does not mention, so it cannot be used at
-- all: the sweep full-scans game_player_missions every five minutes, on a table
-- that only ever grows.
--
-- The per-player call from api/missions/overview.php does name user_id and is
-- already served by the existing index, which is why this went unnoticed.
ALTER TABLE `game_player_missions`
  ADD KEY IF NOT EXISTS `idx_game_player_missions_due` (`status`, `completes_at`);

-- 2. The two quartermaster ceilings ------------------------------------------
-- pw_missions_inventory_usage() runs on every Missions page load and every
-- Market purchase:
--
--     WHERE pl.user_id = ? AND pl.quantity > 0   GROUP BY l.slot, l.stim_effect
--
-- quantity is not in any index, so the rows have to be read to be filtered.
-- Adding it after user_id lets the range be satisfied from the index, and
-- InnoDB appends the primary key so the join to the definitions is unaffected.
ALTER TABLE `game_player_loot`
  ADD KEY IF NOT EXISTS `idx_game_player_loot_held` (`user_id`, `quantity`);

-- 3. Remove a redundant index --------------------------------------------------
-- idx_game_player_loot_user (user_id) is a strict prefix of
-- uq_game_player_loot_item (user_id, loot_definition_id), so every lookup it
-- could serve is already served -- it costs write time and space and answers
-- nothing. Same class as the redundant page_views.visitor_id index removed by
-- sql/migration_forum_analytics_indexes.sql.
--
-- Dropped last, and only after the two additions above, so a partial run can be
-- restarted from the top without ever leaving the table under-indexed.
ALTER TABLE `game_player_loot`
  DROP KEY IF EXISTS `idx_game_player_loot_user`;
