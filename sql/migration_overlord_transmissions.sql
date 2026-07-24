-- Overlord Transmission Control
-- Run once in phpMyAdmin against pantheonwars after deploying.
--
-- Each Overlord gets one configurable result-screen transmission: an opening
-- line, a follow-up line, an enabled switch and a paced "responding" delay.
-- The public quiz uses safe built-in copy until this migration has run.

CREATE TABLE IF NOT EXISTS overlord_transmissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  overlord_id INT UNSIGNED NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  opening_message VARCHAR(500) NOT NULL DEFAULT '',
  followup_message VARCHAR(500) NOT NULL DEFAULT '',
  typing_delay_ms SMALLINT UNSIGNED NOT NULL DEFAULT 700,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_overlord_transmissions_overlord (overlord_id),
  CONSTRAINT fk_overlord_transmissions_overlord FOREIGN KEY (overlord_id) REFERENCES overlords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('transmissions.view', 'View Overlord Transmission Control', 'Lore Management'),
  ('transmissions.edit', 'Edit Overlord quiz-result transmissions', 'Lore Management')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

-- Seed the existing result copy without overwriting later editor changes.
-- Syn's two messages are the configurable opening of his longer dialogue tree;
-- every other Overlord uses these as their complete two-message transmission.
INSERT INTO overlord_transmissions (
  overlord_id, is_enabled, opening_message, followup_message, typing_delay_ms
)
SELECT
  o.id,
  1,
  CASE o.slug
    WHEN 'syn-dravus' THEN 'So... you could be like me.'
    WHEN 'malric-thorne' THEN 'You understand what order costs.'
    WHEN 'korrus-vale' THEN 'You see the pressure points.'
    WHEN 'lysara-venthe' THEN 'You know that care is not weakness.'
    WHEN 'zura-kaleth' THEN 'You understand that roots do their work unseen.'
    WHEN 'maerion-thal' THEN 'Your word carries weight.'
    ELSE ''
  END,
  CASE o.slug
    WHEN 'syn-dravus' THEN 'That should concern you more than it appears to.'
    WHEN 'malric-thorne' THEN 'Come to Cerius. There is work for someone with resolve.'
    WHEN 'korrus-vale' THEN 'Come to Reanium. Let us build something that survives.'
    WHEN 'lysara-venthe' THEN 'Come to Asmecu. The tide always has room for one more.'
    WHEN 'zura-kaleth' THEN 'Come to Babki Prime. There is room to grow.'
    WHEN 'maerion-thal' THEN 'Come to High Hammer. Let us see what you stand for.'
    ELSE ''
  END,
  700
FROM overlords o
ON DUPLICATE KEY UPDATE overlord_id = VALUES(overlord_id);
