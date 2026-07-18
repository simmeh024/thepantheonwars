-- Forum features batch: polls, thread subscriptions, report categories.
-- Run once in phpMyAdmin against the pantheonwars DB after deploying.

-- Report triage category. Existing rows get 'other' by default (safe,
-- since nothing previously categorized them).
ALTER TABLE content_reports
  ADD COLUMN category ENUM('spam','harassment','off_topic','spoiler_untagged','other') NOT NULL DEFAULT 'other' AFTER reason;

-- One optional poll per topic, created only at topic-creation time (not
-- editable afterward in this version -- options shouldn't change once
-- anyone may have voted). Single-choice, one vote per member.
CREATE TABLE IF NOT EXISTS topic_polls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  topic_id INT UNSIGNED NOT NULL,
  question VARCHAR(300) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_topic_poll (topic_id),
  CONSTRAINT fk_topic_polls_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS topic_poll_options (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  poll_id INT UNSIGNED NOT NULL,
  label VARCHAR(200) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_poll_options_poll (poll_id, sort_order),
  CONSTRAINT fk_poll_options_poll FOREIGN KEY (poll_id) REFERENCES topic_polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One vote per (poll, user); re-voting replaces the previous choice
-- (INSERT ... ON DUPLICATE KEY UPDATE) rather than adding a second row.
CREATE TABLE IF NOT EXISTS topic_poll_votes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  poll_id INT UNSIGNED NOT NULL,
  option_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_poll_user (poll_id, user_id),
  KEY idx_poll_votes_option (option_id),
  CONSTRAINT fk_poll_votes_poll FOREIGN KEY (poll_id) REFERENCES topic_polls(id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_votes_option FOREIGN KEY (option_id) REFERENCES topic_poll_options(id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_votes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Watch this thread" -- distinct from topic_bookmarks (a manual save with
-- no notification behaviour). A subscription actually notifies on every
-- new reply. A topic's own creator and anyone who replies to it are
-- auto-subscribed (see api/topics/create.php / api/comments/post.php);
-- members can unsubscribe from the kebab menu at any time.
CREATE TABLE IF NOT EXISTS topic_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  topic_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_subscription (user_id, topic_id),
  KEY idx_subscriptions_topic (topic_id),
  CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_subscriptions_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notifications
  MODIFY COLUMN type ENUM('like','mention','quote','report_resolved','world_available','news_published','topic_reply') NOT NULL;

ALTER TABLE notification_preferences
  ADD COLUMN notif_topic_reply TINYINT(1) NOT NULL DEFAULT 1;
