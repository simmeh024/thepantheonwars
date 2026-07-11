-- migration_overlords.sql
-- Overlord Control: overlords table + worlds.overlord_id FK.
-- Run by hand via phpMyAdmin's SQL tab, then fold into sql/schema.sql per project convention.
--
-- Replaces the free-text worlds.overlord_name/overlord_title/overlord_page_slug
-- columns (kept for now, dropped in a short follow-up migration once the
-- cutover is verified live) with a real overlords table + FK.

CREATE TABLE overlords (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  epithet VARCHAR(100) NOT NULL DEFAULT '',
  world_id INT UNSIGNED NULL,
  pronoun_possessive VARCHAR(10) NOT NULL DEFAULT 'their',
  status ENUM('available','locked') NOT NULL DEFAULT 'locked',
  portrait_image_url VARCHAR(255) NOT NULL DEFAULT '',
  card_teaser VARCHAR(300) NOT NULL DEFAULT '',
  bio_paragraph_1 TEXT NULL,
  bio_paragraph_2 TEXT NULL,
  bio_paragraph_3 TEXT NULL,
  quote_text VARCHAR(400) NOT NULL DEFAULT '',
  quote_cite VARCHAR(150) NOT NULL DEFAULT '',
  accent_color VARCHAR(20) NOT NULL DEFAULT '',
  accent_glow VARCHAR(20) NOT NULL DEFAULT '',
  meta_title VARCHAR(150) NOT NULL DEFAULT '',
  meta_description VARCHAR(300) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_overlords_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE worlds
  ADD COLUMN overlord_id INT UNSIGNED NULL AFTER overlord_page_slug,
  ADD CONSTRAINT fk_worlds_overlord FOREIGN KEY (overlord_id) REFERENCES overlords(id) ON DELETE SET NULL;

INSERT INTO permissions (`key`, label, category) VALUES
  ('overlords.view', 'View Overlord Control', 'Lore Management'),
  ('overlords.edit', 'Create/edit/reorder overlords', 'Lore Management'),
  ('overlords.delete', 'Delete overlords', 'Lore Management')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

-- ============================================================
-- The 6 overlords with a live bio page, transcribed verbatim from their
-- static HTML (not migration_worlds.sql's stale seed strings, which are
-- missing overlord_title for 3 of these and have Asmecu's title wrong --
-- "The Sea Queen" there vs. "The Tidekeeper" on the actual live page).
-- Overlord status is independent of the assigned world's own status (e.g.
-- Korrus Vale and Zura Kaleth have live, working bio pages even though
-- Reanium and Babki Prime are themselves still locked worlds).
-- ============================================================

INSERT INTO overlords (slug, name, epithet, world_id, pronoun_possessive, status, portrait_image_url, card_teaser, bio_paragraph_1, bio_paragraph_2, bio_paragraph_3, quote_text, quote_cite, accent_color, accent_glow, meta_title, meta_description, sort_order) VALUES
('syn-dravus', 'Syn Dravus', 'The Mindweaver', (SELECT id FROM worlds WHERE slug='neoh'), 'his', 'available', 'images/char-syn.jpg',
 'He commands the Overcode that binds Neoh''s Memory Vaults — rewriting loyalty and erasing rebellion, one memory at a time.',
 'Syn Dravus rules Neoh not with armies, but with memory itself. A pulsing third eye at his brow and black tendrils of living circuitry fanning from his skull mark him as something closer to a machine of suppression than a man — every gesture a promise that no secret survives his gaze.',
 'He commands the Overcode that binds Neoh''s Memory Vaults, rewriting loyalty, erasing rebellion, and archiving the city''s past whether its people agree to it or not. Where other Overlords rule through fear of the body, Syn rules through fear of forgetting who you are.',
 'He is patient, surgical, and rarely needs to raise his voice. In Neoh, that''s usually enough.',
 'Why break bones when you can unmake the mind?', '— attributed to Syn Dravus',
 '#a279ec', '#4a2f85',
 'Syn Dravus, The Mindweaver — The Pantheon Wars',
 'Syn Dravus, Overlord of Neoh — a spoiler-free profile of the Pantheon''s master of memory and identity.', 1),

('maerion-thal', 'Maerion Thal', 'The Sky Duke', (SELECT id FROM worlds WHERE slug='high-hammer'), 'his', 'available', 'images/char-maerion.jpg',
 'He commands both sea and sky from a citadel of marble domes and brass vaults, and no storm has ever once touched his flagship.',
 'Maerion Thal rules High Hammer from a citadel of marble domes and brass vaults, where honor duels are settled in leisure gardens above while gears and pistons clatter in the foundries below. He commands both the open water and the sky-galleons that cross it — a Duke who treats chivalry and industry as the same discipline, practiced at different altitudes.',
 'He is the only Overlord who still keeps a formal court, and the only one whose word is said to be worth more than his treaties. Whether that reputation survives contact with what happens beneath the Iron Mountains is a different question, and not one High Hammer''s people ask their Duke directly.',
 'A storm has never once touched his flagship, or so the sailors of the Sapphire Sea insist. Nobody has offered a better explanation than the Duke''s own.',
 'Every duel is won before the first blade is drawn — the rest is just for the crowd.', '— attributed to Maerion Thal',
 '#f0c479', '#8a5a26',
 'Maerion Thal, The Sky Duke — The Pantheon Wars',
 'Maerion Thal, Overlord of High Hammer — a spoiler-free profile of the Duke who commands both sea and sky.', 2),

('malric-thorne', 'Malric Thorne', 'The Black Regent', (SELECT id FROM worlds WHERE slug='cerius'), 'his', 'available', 'images/char-malric.jpg',
 'Thirty-five years ago he ended a democracy with a coup nobody''s avenged. He rules now by what he calls the arithmetic of suffering.',
 'Thirty-five years ago, Malric Thorne ended Cerius''s short-lived democracy with a coup that still hasn''t been avenged. He didn''t just take a throne — he replaced an idea with an algorithm, ruling now by what he calls "the arithmetic of suffering."',
 'He has memorized the speeches of the ruler he deposed, not to honor her, but to dismantle her ideals word by word in front of people who once believed them. His armor is forged from the melted gates of the old People''s Forum; his mercy, by his own admission, is a statistical improbability.',
 'Cerius runs on iron, smoke, and red banners — and on a Regent who has never once let anyone forget who''s in charge.',
 'Bea promised you liberty. I promise you data.', '— attributed to Malric Thorne',
 '#e05a4a', '#4a1712',
 'Malric Thorne, The Black Regent — The Pantheon Wars',
 'Malric Thorne, Overlord of Cerius — a spoiler-free profile of the tyrant who buried a democracy.', 3),

('korrus-vale', 'Korrus Vale', 'The Reactor King', (SELECT id FROM worlds WHERE slug='reanium'), 'his', 'available', 'images/char-korrus.jpg',
 'His body is laced with unstable isotopes, and his kingdom is poisoned by the very ore that keeps it alive.',
 'Korrus Vale''s body is laced with unstable isotopes, his flesh perpetually lit by the sickly glow of the core embedded in his chest. Even the other Overlords keep their distance — Reanium''s ruler is less a soldier than a walking meltdown, an omen that happens to wear armor.',
 'He governs a poisoned kingdom where survival is measured in rads, and where his people mine the very ore that slowly kills them. He recites casualty reports the way other rulers recite decrees — precise, unemotional, and final.',
 'Whatever mercy Korrus Vale is capable of, he keeps to himself. Everything else, he keeps radioactive.',
 '37 dead in Sector 12 today. Efficiency improved by 2.3%.', '— attributed to Korrus Vale',
 '#8fe04a', '#335c22',
 'Korrus Vale, The Reactor King — The Pantheon Wars',
 'Korrus Vale, Overlord of Reanium — a spoiler-free profile of the walking meltdown who rules a poisoned kingdom.', 4),

('lysara-venthe', 'Lysara Venthe', 'The Tidekeeper', (SELECT id FROM worlds WHERE slug='asmecu'), 'her', 'available', 'images/char-lysara.jpg',
 'A maternal sovereign who calls her citizens "my tides" — and forbids fishing in her waters for reasons she won''t fully explain.',
 'Lysara Venthe rules Asmecu from a palace afloat on the eye of something vast and unseen, a crown of flame-like coral resting on her brow. She is, by every account, beloved — a maternal sovereign who calls her citizens "my tides," and means it.',
 'She is also the only Overlord who refuses to attend meetings at the Nexus Veil, and the only one who has banned deep-sea fishing in her waters "for safety." Whether that''s the whole truth of her reign, or only the surface of it, is a question Asmecu''s people have learned not to ask too loudly.',
 'Every wave breaks twice, she says — first on the shore, then on the soul. Nobody in Asmecu has quite figured out which one she means for them.',
 'Every wave breaks twice — first upon the shore, then upon the soul.', '— attributed to Lysara Venthe',
 '#4fb3e8', '#1c4666',
 'Lysara Venthe, The Tidekeeper — The Pantheon Wars',
 'Lysara Venthe, Overlord of Asmecu — a spoiler-free profile of the sea queen every citizen loves and no one quite trusts.', 5),

('zura-kaleth', 'Zura Kaleth', 'The Rootbinder', (SELECT id FROM worlds WHERE slug='babki-prime'), 'her', 'available', 'images/char-zura.jpg',
 'Her sanctuary gardens are famous for their beauty. Guests who get too close to the flowers rarely leave the way they arrived.',
 'Zura Kaleth''s skin has gone the color and texture of bark and moss, twisted horns rising from her brow like broken branches — a sovereign who has stopped pretending to be entirely human. She rules Babki Prime''s sentient jungles, where flora and politics have grown into the same suffocating organism.',
 'Her "Sanctuary Gardens" are famous across the Pantheon for their beauty. Visitors are advised, gently but repeatedly, not to get too close to the flowers. Guests who ignore that advice tend not to leave the way they arrived.',
 'She preaches harmony with nature. Nature, in Babki Prime, has teeth.',
 'Civilization is a weed. I am the gardener.', '— attributed to Zura Kaleth',
 '#4f9d5c', '#1c3820',
 'Zura Kaleth, The Rootbinder — The Pantheon Wars',
 'Zura Kaleth, Overlord of Babki Prime — a spoiler-free profile of the sovereign whose gardens are more dangerous than they look.', 6);

-- Worlds with a free-text overlord_name today but no bio page yet -- seeded
-- as locked overlords rows (name + world only) so those world cards don't
-- regress to "no overlord" once worlds.js switches to reading the join.

INSERT INTO overlords (slug, name, world_id, status, sort_order) VALUES
('krev-ashmane', 'Krev Ashmane', (SELECT id FROM worlds WHERE slug='sed'), 'locked', 7),
('veylan-dros', 'Veylan Dros', (SELECT id FROM worlds WHERE slug='geof-v'), 'locked', 8);

-- Backfill worlds.overlord_id from the newly-created overlords rows.

UPDATE worlds w JOIN overlords o ON o.world_id = w.id SET w.overlord_id = o.id;
