-- migration_soundtracks.sql
-- Soundtrack Control: soundtracks table replacing the single hand-authored
-- .soundtrack-panel block in soundtracks.html with a real, admin-managed CRUD
-- (Lore Management > Soundtrack Control). Flat entity, same list/modal/reorder
-- pattern as Overlord Control / Known Figures Control.
--
-- An admin pastes a normal open.spotify.com share link (album, playlist, or
-- track); the create/update endpoints parse it once server-side into
-- spotify_embed_type + spotify_embed_id so both the admin preview and the
-- public page can build the iframe embed src from a fixed template rather
-- than re-parsing the URL in multiple places. The original share link is
-- kept as spotify_url for the "Listen on Spotify" badge link (preserves any
-- ?si= tracking param the admin pasted).
--
-- Run by hand via phpMyAdmin's SQL tab, then fold into sql/schema.sql per
-- project convention.

CREATE TABLE IF NOT EXISTS soundtracks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  eyebrow VARCHAR(150) NOT NULL DEFAULT '',
  heading VARCHAR(200) NOT NULL DEFAULT '',
  description TEXT NULL,
  spotify_url VARCHAR(500) NOT NULL DEFAULT '',
  spotify_embed_type ENUM('album','playlist','track') NOT NULL DEFAULT 'album',
  spotify_embed_id VARCHAR(64) NOT NULL DEFAULT '',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('soundtracks.view', 'View Soundtrack Control', 'Lore Management'),
  ('soundtracks.edit', 'Create/edit/reorder Soundtracks', 'Lore Management'),
  ('soundtracks.delete', 'Delete Soundtracks', 'Lore Management')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

-- ============================================================
-- The one soundtrack already live on soundtracks.html, transcribed verbatim
-- so the cutover to the DB-backed page is visually identical.
-- ============================================================

INSERT INTO soundtracks (
  eyebrow, heading, description, spotify_url, spotify_embed_type, spotify_embed_id,
  is_published, sort_order
) VALUES (
  '♪ Book One', 'Thirteen Worlds, Thirteen Songs',
  'The Mindweaver''s Lie comes with its own soundtrack — thirteen original compositions, one written for every world you''re about to enter. They''re also pressed into a QR code inside the back cover of the physical book. I''d suggest hitting play before you turn the first page.',
  'https://open.spotify.com/album/4F3gZb2oOUarb0gM2KwSar?si=FUDqkC25TPewM4YBTeNTIg', 'album', '4F3gZb2oOUarb0gM2KwSar',
  1, 1
);
