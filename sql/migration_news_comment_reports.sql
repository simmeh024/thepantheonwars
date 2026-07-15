-- News comment reports
-- Run once in phpMyAdmin after sql/migration_news_comments.sql.
-- Extends the shared moderation queue without creating a second report system.

ALTER TABLE content_reports
  MODIFY COLUMN target_type ENUM('topic','comment','news_comment') NOT NULL;
