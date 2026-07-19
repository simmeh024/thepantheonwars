-- Adds one-time reputation awards to the reading shelf. Run this after
-- migration_reading_progress.sql on installations where that table already
-- exists. A start awards 3 reputation; a first completion awards 5.
ALTER TABLE user_book_progress
  ADD COLUMN IF NOT EXISTS started_at DATETIME NULL DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS finished_at DATETIME NULL DEFAULT NULL AFTER started_at;
