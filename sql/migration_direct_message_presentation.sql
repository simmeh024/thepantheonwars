-- Private message presentation additions: personal conversation pins and
-- short-lived typing indicators. Run once in phpMyAdmin after deploying the
-- matching PHP/JS/CSS files.

CREATE TABLE IF NOT EXISTS direct_conversation_preferences (
  user_id INT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, conversation_id),
  KEY idx_direct_conversation_preferences_pinned (user_id, is_pinned, updated_at),
  CONSTRAINT fk_direct_conversation_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_direct_conversation_preferences_conversation FOREIGN KEY (conversation_id) REFERENCES direct_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS direct_message_typing (
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (conversation_id, user_id),
  KEY idx_direct_message_typing_active (conversation_id, updated_at),
  CONSTRAINT fk_direct_message_typing_conversation FOREIGN KEY (conversation_id) REFERENCES direct_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_direct_message_typing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
