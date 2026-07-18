-- Post-level Reputation system. Every member starts at 0. A topic or
-- comment gives its author +1; a like given to that content gives its
-- author +2 (and -2 back on unlike -- see api/messages/like.php). Admins
-- define named levels at configurable reputation thresholds; the bar shown
-- on the profile page and next to each forum poster fills toward whichever
-- level comes next. Run once in phpMyAdmin after deploying the
-- accompanying application code.

ALTER TABLE users
  ADD COLUMN reputation INT UNSIGNED NOT NULL DEFAULT 0 AFTER presence_status;

CREATE TABLE IF NOT EXISTS reputation_levels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  threshold INT UNSIGNED NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#a279ec',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reputation_levels_threshold (threshold)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Level 0 is the starting rank every account is seeded at; "next level at
-- 5" from the original spec becomes the first rank above that.
INSERT INTO reputation_levels (name, threshold, color) VALUES
  ('Newcomer', 0, '#c7ccd6'),
  ('Regular', 5, '#a279ec')
ON DUPLICATE KEY UPDATE name = VALUES(name), color = VALUES(color);

INSERT INTO permissions (`key`, label, category) VALUES
  ('reputation.view', 'View Reputation Levels', 'Community'),
  ('reputation.edit', 'Manage reputation levels', 'Community')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
