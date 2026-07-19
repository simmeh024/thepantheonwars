-- Direct member messaging. Deploy the PHP/HTML/CSS/JS first, then run this
-- once in phpMyAdmin. All rows use utf8mb4 to retain names and message text.

CREATE TABLE IF NOT EXISTS direct_conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_low_id INT UNSIGNED NOT NULL,
  user_high_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  last_message_id BIGINT UNSIGNED NULL,
  last_message_at DATETIME NULL,
  user_low_last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  user_high_last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_direct_conversation_pair (user_low_id, user_high_id),
  KEY idx_direct_conversations_recent (last_message_at, id),
  CONSTRAINT fk_direct_conversations_low FOREIGN KEY (user_low_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_direct_conversations_high FOREIGN KEY (user_high_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_direct_conversations_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS direct_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_user_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_direct_messages_conversation (conversation_id, id),
  KEY idx_direct_messages_sender_created (sender_user_id, created_at),
  CONSTRAINT fk_direct_messages_conversation FOREIGN KEY (conversation_id) REFERENCES direct_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_direct_messages_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_blocks (
  blocker_user_id INT UNSIGNED NOT NULL,
  blocked_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (blocker_user_id, blocked_user_id),
  CONSTRAINT fk_user_blocks_blocker FOREIGN KEY (blocker_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_blocks_blocked FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notifications
  MODIFY COLUMN type ENUM('like','mention','quote','report_resolved','world_available','news_published','topic_reply','icon_unlocked','direct_message') NOT NULL,
  ADD COLUMN IF NOT EXISTS conversation_id BIGINT UNSIGNED NULL AFTER news_slug,
  ADD COLUMN IF NOT EXISTS direct_message_id BIGINT UNSIGNED NULL AFTER conversation_id,
  ADD KEY idx_notification_conversation (conversation_id),
  ADD CONSTRAINT fk_notifications_conversation FOREIGN KEY (conversation_id) REFERENCES direct_conversations(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_notifications_direct_message FOREIGN KEY (direct_message_id) REFERENCES direct_messages(id) ON DELETE CASCADE;

ALTER TABLE content_reports
  MODIFY COLUMN target_type ENUM('topic','comment','news_comment','direct_message') NOT NULL;
