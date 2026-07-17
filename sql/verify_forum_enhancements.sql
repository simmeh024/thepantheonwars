-- verify_forum_enhancements.sql
-- Read-only checklist for migration_forum_enhancements.sql. Safe to run any
-- number of times; queries INFORMATION_SCHEMA only, never touches data.
-- Scan the output for any row whose status is not OK.

SELECT
  'forum_boards.accent_color column' AS check_item,
  IF(COUNT(*) > 0, 'OK', 'MISSING') AS status
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'pantheonwars' AND TABLE_NAME = 'forum_boards' AND COLUMN_NAME = 'accent_color'

UNION ALL

SELECT 'topics.edited_by column', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'pantheonwars' AND TABLE_NAME = 'topics' AND COLUMN_NAME = 'edited_by'

UNION ALL

SELECT 'topics.fk_topics_edited_by constraint', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = 'pantheonwars' AND TABLE_NAME = 'topics' AND CONSTRAINT_NAME = 'fk_topics_edited_by'

UNION ALL

SELECT 'comments.edited_by column', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'pantheonwars' AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'edited_by'

UNION ALL

SELECT 'comments.fk_comments_edited_by constraint', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = 'pantheonwars' AND TABLE_NAME = 'comments' AND CONSTRAINT_NAME = 'fk_comments_edited_by'

UNION ALL

SELECT 'forum_board_seen table exists', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'pantheonwars' AND TABLE_NAME = 'forum_board_seen'

UNION ALL

SELECT 'forum_topic_seen table exists', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'pantheonwars' AND TABLE_NAME = 'forum_topic_seen'

UNION ALL

SELECT 'topics FULLTEXT ft_topics_title_body', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'pantheonwars' AND TABLE_NAME = 'topics' AND INDEX_NAME = 'ft_topics_title_body'

UNION ALL

SELECT 'comments FULLTEXT ft_comments_body', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'pantheonwars' AND TABLE_NAME = 'comments' AND INDEX_NAME = 'ft_comments_body';
