-- migration_worlds.sql
-- World Control: worlds, world_layers, world_layer_sublocations, world_landmarks
-- Run by hand via phpMyAdmin's SQL tab, then fold into sql/schema.sql per project convention.

CREATE TABLE worlds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  tagline VARCHAR(200) NOT NULL DEFAULT '',
  card_blurb VARCHAR(300) NOT NULL DEFAULT '',
  thumb_image_url VARCHAR(255) NOT NULL DEFAULT '',
  portrait_image_url VARCHAR(255) NOT NULL DEFAULT '',
  overlord_name VARCHAR(100) NOT NULL DEFAULT '',
  overlord_title VARCHAR(100) NOT NULL DEFAULT '',
  overlord_page_slug VARCHAR(100) NOT NULL DEFAULT '',
  status ENUM('available','locked') NOT NULL DEFAULT 'locked',
  lore_status_label VARCHAR(100) NOT NULL DEFAULT 'Lore Coming Soon',
  intro_paragraph_1 TEXT NULL,
  intro_paragraph_2 TEXT NULL,
  layout_orientation ENUM('vertical','horizontal') NOT NULL DEFAULT 'horizontal',
  altitude_top_label VARCHAR(100) NOT NULL DEFAULT '',
  altitude_bottom_label VARCHAR(100) NOT NULL DEFAULT '',
  map_thumb_image_url VARCHAR(255) NOT NULL DEFAULT '',
  map_full_image_url VARCHAR(255) NOT NULL DEFAULT '',
  map_caption VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE world_layers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  name VARCHAR(100) NOT NULL,
  theme_tags VARCHAR(200) NOT NULL DEFAULT '',
  tagline VARCHAR(150) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  quote_text VARCHAR(400) NOT NULL DEFAULT '',
  quote_cite VARCHAR(150) NOT NULL DEFAULT '',
  tint_key VARCHAR(20) NOT NULL DEFAULT 'gold',
  CONSTRAINT fk_world_layers_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE world_layer_sublocations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  layer_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  label VARCHAR(100) NOT NULL,
  CONSTRAINT fk_world_layer_sublocations_layer FOREIGN KEY (layer_id) REFERENCES world_layers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE world_landmarks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_id INT UNSIGNED NOT NULL,
  layer_id INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  kind ENUM('restricted','distant') NOT NULL DEFAULT 'restricted',
  name VARCHAR(100) NOT NULL,
  tag_label VARCHAR(150) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  quote_text VARCHAR(400) NOT NULL DEFAULT '',
  quote_cite VARCHAR(150) NOT NULL DEFAULT '',
  CONSTRAINT fk_world_landmarks_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
  CONSTRAINT fk_world_landmarks_layer FOREIGN KEY (layer_id) REFERENCES world_layers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('worlds.view', 'View World Control', 'Lore Management'),
  ('worlds.edit', 'Create/edit/reorder worlds and their layers', 'Lore Management'),
  ('worlds.delete', 'Delete worlds', 'Lore Management')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

-- ============================================================
-- Worlds (top-level cards)
-- ============================================================

INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('neoh', 'Neoh', 'The City That Never Forgets', 'A rain-drowned metropolis built on the information black market.', 'images/world-neoh.jpg', 'images/char-syn.jpg', 'Syn Dravus', '', 'overlord-syn-dravus', 'available', 'Lore Coming Soon', 'Neoh bleeds neon into darkness — a city where rain slicks the streets like mirrors and the sky glows with the pallor of endless advertisements. Faces flicker ten stories high on holographic billboards, watching every movement below. In Neoh, memory and identity don''t belong to the people. They belong to the city itself, and to the Overlord who owns its every circuit.', 'Neoh isn''t one place — it''s six tiers stacked one on top of the other, from the sunlit Spires down to a silence even the city''s own surveyors won''t map, with a black site woven through all of them that nobody admits exists. Click a floor to open it.', 'vertical', 'above the smog line', 'no signal beyond this point', 'images/neoh-map-thumb.jpg?v=2', 'images/neoh-map.jpg?v=2', 'A smuggled cross-section of Neoh — from Syn''s Citadel down to whatever the Deepcircuit actually is.', 1);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('high-hammer', 'High Hammer', 'The Marble Heart of Industry', 'Domes, vaults, and airship harbors — where storms part for the Sky Duke''s flagship.', 'images/world-highhammer.jpg', 'images/char-maerion.jpg', 'Maerion Thal', 'The Sky Duke', 'overlord-maerion-thal', 'available', 'Lore Coming Soon', 'High Hammer rises in layers of marble domes and brass vaults, its skyline broken by chimneys breathing steam into a sky crossed by sky-galleons. Chivalry meets steam-age invention here: honor duels and leisure gardens above, gears and pistons clattering below, and an Overlord who commands both sea and sky.', 'High Hammer runs in a single line from harbor to peak — from the open water where every flag flies, through the Market and the Guild Quarter''s forges, up to the Duke''s own citadel and the smoking foundries beneath the Iron Mountains. Click a district to open it.', 'horizontal', '', '', 'images/highhammer-map.jpg', 'images/highhammer-map.jpg', 'The Great Harbor of High Hammer — from open water to the Iron Mountains.', 2);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('cerius', 'Cerius', 'The Throne Built on Smoke', 'Iron, fire, and red banners over a people who remember what they lost.', 'images/world-cerius.jpg', 'images/char-malric.jpg', 'Malric Thorne', 'The Black Regent', 'overlord-malric-thorne', 'available', 'Lore Coming Soon', 'Cerius runs on iron, smoke, and red banners — a furnace world where every skyline is a foundry and every foundry answers to one Regent. Thirty-five years ago Malric Thorne ended a short-lived democracy with a coup nobody has avenged, and rebuilt the whole world around what he calls "the arithmetic of suffering": a ledger where quotas are met, or else.', 'Cerius climbs from the Iron Gate at its base, through smelters and worker districts thick with ash, up to the Black Regent''s Keep watching over all of it from the mountains behind. Click a district to open it.', 'horizontal', '', '', 'images/cerius-map.jpg', 'images/cerius-map.jpg', 'Cerius, the Furnace World — from the Iron Gate to the Black Regent''s Keep.', 3);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('reanium', 'Reanium', 'The Wasteland That Remembers', 'Glassed by the first war between gods, its survivors driven underground.', 'images/world-reanium.jpg', '', 'Korrus Vale', '', 'overlord-korrus-vale', 'locked', 'Lore Coming Soon', NULL, NULL, 'horizontal', '', '', '', '', '', 4);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('asmecu', 'Asmecu', 'The Palace Above the Abyss', 'A city afloat on the eye of something vast and unseen.', 'images/world-asmecu.jpg', 'images/char-lysara.jpg', 'Lysara Venthe', 'The Sea Queen', 'overlord-lysara-venthe', 'available', 'Lore Coming Soon', 'Asmecu rises from open water in domes of living coral and sea-bleached marble, a city that was never built so much as grown — coaxed upward, reef by reef, at the will of the woman its people call their tide. Lysara Venthe rules from a palace afloat on the eye of something vast and unseen, beloved by every citizen she calls "my tides," and trusted completely by none of them.', 'Asmecu spreads outward from the Coral Palace in a ring of canals, guildhalls, and harbors, with the charted world thinning out fast the further you sail from the Market at its heart. Click a district to open it.', 'horizontal', '', '', 'images/asmecu-map.jpg', 'images/asmecu-map.jpg', 'Asmecu, the Sea of a Hundred Ports — from the Coral Palace to the edge of the charted waters.', 5);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('babki-prime', 'Babki Prime', 'The Jungle That Feels Pain', 'Living weapons, and prisons grown from trees that used to be people.', 'images/world-babki.jpg', '', 'Zura Kaleth', '', 'overlord-zura-kaleth', 'locked', 'Lore Coming Soon', NULL, NULL, 'horizontal', '', '', '', '', '', 6);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('sed', 'Sed', 'The Hellscape Under a Black Sun', 'Slave mines where the ore itself records the sound of torture.', 'images/world-sed.jpg', '', 'Krev Ashmane', '', '', 'locked', 'Lore Coming Soon', NULL, NULL, 'horizontal', '', '', '', '', '', 7);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('geof-v', 'Geof V', 'The March of Iron', 'Airships and lockstep regiments over a fortress built for commerce.', 'images/world-geofv.jpg', '', 'Veylan Dros', '', '', 'locked', 'Lore Coming Soon', NULL, NULL, 'horizontal', '', '', '', '', '', 8);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('beoctica', 'Beoctica', 'The City Without Shadows', 'Rigid towers, unfeeling geometry, and drones that never stop watching.', 'images/world-beoctica.jpg', '', '', '', '', 'locked', 'Lore Coming Soon', NULL, NULL, 'horizontal', '', '', '', '', '', 9);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('terek-ii', 'Terek II', 'The World That Never Stops Marching', 'Oceans of soldiers and colossal war machines, moving as one will.', 'images/world-terek.jpg', '', '', '', '', 'locked', 'Lore Coming Soon', NULL, NULL, 'horizontal', '', '', '', '', '', 10);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('valerium-prime', 'Valerium Prime', 'The Desert of Three Moons', 'Fire and silence, watched by three moons that judge without mercy.', 'images/world-valerium.jpg', '', '', '', '', 'locked', 'Lore Coming Soon', NULL, NULL, 'horizontal', '', '', '', '', '', 11);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('vermillia-xi', 'Vermillia XI', 'The Domes That Aren''t for Protection', 'A rust-and-dust wasteland breathing only inside its glass domes.', 'images/world-vermillia.jpg', '', '', '', '', 'locked', 'Lore Coming Soon', NULL, NULL, 'horizontal', '', '', '', '', '', 12);
INSERT INTO worlds (slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url, overlord_name, overlord_title, overlord_page_slug, status, lore_status_label, intro_paragraph_1, intro_paragraph_2, layout_orientation, altitude_top_label, altitude_bottom_label, map_thumb_image_url, map_full_image_url, map_caption, sort_order) VALUES ('nexus-veil', 'The Nexus Veil', 'The Table With Thirteen Seats', 'Where the Overlords convene, around a seat that''s stood empty for ten thousand years.', 'images/world-nexusveil.jpg', '', '', '', '', 'locked', 'Lore Coming Soon', NULL, NULL, 'horizontal', '', '', '', '', '', 13);

-- ============================================================
-- Layers, sublocations, and landmarks (available worlds only)
-- ============================================================

INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='neoh'), 1, 'The Spires', 'Loyalty · Privilege · Isolation', 'Syn''s Pinnacle', 'The crown of Neoh: arcologies that pierce the smog and glitter with neon constellations. Only Syn''s enforcers, priests, and magnates live here, wrapped in engineered climates that mimic a perfect eternal dawn. Every wall hums with surveillance; every window reflects the Overlord''s vision instead of the city below.', 'Up here, the air is pure, the light eternal, and the food divine. But every breath is borrowed, every sunrise a test.', 'Intercepted whisper, anonymous exile', 'gold');
SET @layer_neoh_1 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_1, 1, 'Syn''s Citadel');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_1, 2, 'Surveillance Arrays');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_1, 3, 'Drone Launch Platforms');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_1, 4, 'Ascendant Guard Headquarters');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_1, 5, 'Overcode Relay Towers');
INSERT INTO world_landmarks (world_id, layer_id, sort_order, kind, name, tag_label, description, quote_text, quote_cite) VALUES ((SELECT id FROM worlds WHERE slug='neoh'), @layer_neoh_1, 1, 'restricted', 'Vault 17', 'The Broken Seal', 'A forbidden facility carved beneath Neoh''s Black Grid, its existence denied in every official record — though its seal pierces all the way up through the Spires, visible from the sky if you know where to look. Triple-sealed doors. Guardians, human and otherwise. Rumors of shard fragments too unstable to wield, and corrupted Overlord schematics no one was meant to find. Its primary lock failed — or was sabotaged — and the black markets have never been the same since.', 'Vault 17 does not open. It leaks.', 'Inscription in Neoh smuggler graffiti, Cycle 28');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='neoh'), 2, 'The Uppercircuit', 'Stability · Illusion · Contentment', 'The Middle Tiers', 'Suspended platforms between the elite towers and the smog curtain, home to the clerks, technicians, and minor officers who keep Neoh''s systems humming. High enough to escape the smog, far below the Spires'' sterile perfection — cleaner and safer than the depths, but everyone knows it''s one bad cycle from collapse.', 'We are too clean for the Undercircuit, too dirty for the Spires. We are the city''s stomach — fed scraps and expected to stay quiet.', 'Anonymous market scribe', 'purple');
SET @layer_neoh_2 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_2, 1, 'Corporate Headquarters');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_2, 2, 'Shopping Districts');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_2, 3, 'Transport Hubs');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_2, 4, 'Residential Towers');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_2, 5, 'Elevated Walkways');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_2, 6, 'Monorail System');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_2, 7, 'Public Parks');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='neoh'), 3, 'The Black Grid', 'Neon · Memory · Identity', 'Street Level', 'Neoh proper: rain-slicked streets under a sky of shifting advertisements, faces projected ten stories high. This is Syn Dravus''s public face — megacorp temples, black-market spell-hackers, and Memory Vaults that quietly archive everyone''s past, whether they agreed to it or not. Kael Veyr''s story starts in an alley just off this level.', 'No stars pierce the smog. Only the glow of shifting light, and the unblinking face projected on a thousand screens.', 'Codex note, Neoh entry', 'purple');
SET @layer_neoh_3 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_3, 1, 'Holographic Advertisements');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_3, 2, 'Restaurants & Markets');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_3, 3, 'Luxury Living');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_3, 4, 'Memory Vaults');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_3, 5, 'Megacorp Temples');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='neoh'), 4, 'The Undercircuit', 'Poverty · Survival · Shadows', 'Slum-Lattice / Parasite Grid', 'Beneath Neoh''s lowest platforms: flooded tunnels, collapsed ducts, and gutted cooling chambers where the ones who fell through the cracks make homes out of scavenged polymer and stolen power. Not citizens. Not recognized. To the surface, this level doesn''t officially exist.', 'It isn''t home. It''s what''s left when home won''t have you.', 'Graffiti scratched into a conduit wall', 'orange');
SET @layer_neoh_4 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_4, 1, 'Support Pillars');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_4, 2, 'Maintenance Tunnels');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_4, 3, 'Abandoned Subway');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_4, 4, 'Cargo Elevators');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_4, 5, 'Smuggler Hideouts');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_4, 6, 'Black Market Augment Clinics');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_4, 7, 'Hacker Dens');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='neoh'), 5, 'The Sewers', 'Abandonment · Pestilence', '"The Forgotten Gut"', 'The absolute floor of Neoh — even the Undercircuit denies it exists. Dead-flow channels, collapsed cisterns, and purge shafts that flood without warning. Not even Protectors patrol down here; step inside, and you''re cut off from Syn''s gaze entirely. For them, that''s worse than any hazard the tunnels can throw at you.', 'Above, they fear the Overlords. Below, we fear the pipes. The pipes don''t serve Syn. They don''t serve anyone.', 'Chalk etched on a sewer wall', 'green');
SET @layer_neoh_5 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_5, 1, 'Sewer Systems');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_5, 2, 'Power Plants');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_5, 3, 'Underground Waterways');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_5, 4, 'Glowing Fungi Caverns');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_5, 5, 'Hidden Safehouses');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_5, 6, 'Kuro''s Workshop');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_5, 7, 'Pier 178');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_5, 8, 'Maintenance Shafts to Vault 17');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='neoh'), 6, 'The Deepcircuit', 'Silence · Decay · The Unknown', 'Below This Layer, Only Silence', 'Beneath even the Sewers, past every marked shaft and mapped tunnel, Neoh''s foundations give way to something older than the city built on top of them — corridors that predate Syn''s rule, structures that match no known architecture, and readings that make surveyor drones lose their signal one by one. No official expedition has ever returned with a full report. The ones who do come back don''t agree on what they saw.', 'Every drone we send down goes quiet at the same depth. Not damaged. Not destroyed. Just — quiet.', 'Ascendant Guard survey log, redacted', 'black');
SET @layer_neoh_6 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_6, 1, 'Origins Unknown');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_6, 2, 'Data Corruption Zone');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_6, 3, 'Structural Instability');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_6, 4, 'Ancient Infrastructure');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_neoh_6, 5, 'Contact Lost');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='high-hammer'), 1, 'The Sapphire Sea', 'Open Water · Trade Winds · Parting Storms', 'Where the Duke''s Word Is Law', 'Past the harbor walls, the sea belongs to Maerion Thal alone. Shipping lanes stretch to the horizon, worked by every flag that can afford his tariffs — and a few that can''t, and pay a different price. Sailors swear storms bend around his skyships before they''re ordered to; whether that''s a captain''s blessing or an Overlord''s leash depends on who''s asking.', 'I''ve sailed under six flags and one god. Out here, only one of them controls the weather.', 'Foreign merchant captain, harbor log', 'teal');
SET @layer_high_hammer_1 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_1, 1, 'Shipping Lanes');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_1, 2, 'Naval Patrol Routes');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_1, 3, 'The Drowned Bell');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_1, 4, 'Skyship Descent Corridors');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='high-hammer'), 2, 'Merchant Harbor', 'Trade · Tariffs · Patched Sails', 'Every Flag Flies Here', 'Wooden traders with patched sails share the water with rivet-lined ironclads, while airships descend on ropes and pulleys to unload cargo no customs officer ever quite finishes counting. It''s the loudest, most honest part of the city — everyone here is selling something, and everyone knows it.', 'Ask a smuggler what''s in the crate. Ask a customs man what he''s paid to believe.', 'Dockside proverb', 'gold');
SET @layer_high_hammer_2 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_2, 1, 'The Long Docks');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_2, 2, 'Customs House');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_2, 3, 'Lighthouse of Saints');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_2, 4, 'Cargo Cranes');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='high-hammer'), 3, 'Lower City', 'Labor · Taverns · Low Ceilings', 'Where the Work Never Stops', 'Coal haulers and dockworkers pack the tight brick streets beneath the citadel''s shadow, going home each night to tenements that shake when the Foundries fire up. The taverns here are louder and more honest than anything in Noble Heights — nobody in the Lower City has the luxury of pretending.', 'The nobles call it Lower City. We just call it home.', 'Dockworker, Guild census interview', 'orange');
SET @layer_high_hammer_3 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_3, 1, 'Dockworkers'' Row');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_3, 2, 'The Rusted Anchor Tavern');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_3, 3, 'Coalhauler Alley');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_3, 4, 'Tenement Stacks');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='high-hammer'), 4, 'Grand Market', 'Commerce · Festival · The City''s Pulse', 'The City''s Beating Heart', 'Every road in High Hammer bends toward this square eventually. Spice stalls, brass bands, and auctioneers compete for attention beneath the statue of the admiral who first sailed these waters — a monument the current Duke has never once stopped to visit.', 'Come for the spice. Stay for the gossip. Leave before the pickpockets notice you.', 'Market crier''s call', 'gold');
SET @layer_high_hammer_4 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_4, 1, 'The Founder''s Statue');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_4, 2, 'Spice Stalls');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_4, 3, 'Festival Grounds');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_4, 4, 'Auction House');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='high-hammer'), 5, 'Guild Quarter', 'Gears · Ambition · Dreadnoughts', 'Where the City Dreams in Brass', 'Engineers and artisans forge High Hammer''s dreadnoughts and clockwork wonders here, guarding their patents as jealously as any noble guards a title. An apprenticeship in the Guild Quarter can make a dockworker''s child wealthier than half of Noble Heights — if the Guild decides to let them in.', 'A good idea is worth a fortune. A patented one is worth a war.', 'Guildmaster''s ledger, margin note', 'orange');
SET @layer_high_hammer_5 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_5, 1, 'The Engineers'' Hall');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_5, 2, 'Patent Vaults');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_5, 3, 'Clockwork Workshops');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_5, 4, 'Dreadnought Drydocks');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='high-hammer'), 6, 'The Grand Citadel', 'Ceremony · Marble · A Seat Rarely Sat In', 'A Throne Rarely Sat In', 'Domes and vaulted halls crown the city as the formal seat of Maerion Thal''s rule — though the Duke himself is rarely home. Stewards and seneschals govern in his name from the Cartography Archive and the Signal Spire, relaying his word from wherever The Unmoored Grace happens to be anchored that week.', 'We serve a Duke we see twice a year. The rest of the time, we serve his absence.', 'Citadel steward, private correspondence', 'gold');
SET @layer_high_hammer_6 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_6, 1, 'The Sky Duke''s Hall');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_6, 2, 'The Cartography Archive');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_6, 3, 'Signal Spire');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='high-hammer'), 7, 'Noble Heights', 'Aristocracy · Gardens · Quiet Schemes', 'Marble & Manners', 'Leisure gardens and manor estates line the promenade above the smoke line, where High Hammer''s aristocracy settles its grudges the old-fashioned way — at dawn, with seconds present, in the Dueling Courts. Every duel is legal here. Every duel is also, somehow, about something else entirely.', 'Nobody in Noble Heights has ever dueled over honor. They''ve dueled over everything honor was covering for.', 'Anonymous second, dueling registry', 'teal');
SET @layer_high_hammer_7 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_7, 1, 'Leisure Gardens');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_7, 2, 'Dueling Courts');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_7, 3, 'Manor Row');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_7, 4, 'The Silk Promenade');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='high-hammer'), 8, 'The Iron Mountains', 'Ore · Old Stone · Rumors', 'Ore & Old Stone', 'The peaks feed the Foundries below with ore hauled down a switchback road that has claimed more wagons than anyone in the Guild Quarter cares to admit. Miners here are loyal to the Guild''s pay more than the Duke''s flag, and they trade stories about ruins older than High Hammer itself, half-buried in the high passes.', 'The mountain doesn''t care whose banner you fly. It only cares whether you dug your supports deep enough.', 'Foreman''s warning, posted at every shaft entrance', 'slate');
SET @layer_high_hammer_8 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_8, 1, 'The Ore Veins');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_8, 2, 'Miner''s Rest');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_8, 3, 'Old Watchtowers');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_8, 4, 'The Long Switchback Road');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='high-hammer'), 9, 'The Great Foundries', 'Fire · Smoke · Production', 'Where the City Burns Bright', 'If the Guild Quarter is where High Hammer dreams, the Foundries are where it sweats. Furnaces run day and night casting dreadnought hulls, and the smoke over the eastern flank never fully clears. The Guild Quarter gets the credit for every ship launched; the night shift here gets the burn scars.', 'They put our name on the blueprints and their name on the hull.', 'Foundry line worker, overheard at shift change', 'red');
SET @layer_high_hammer_9 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_9, 1, 'The Furnace Halls');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_9, 2, 'Slag Yards');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_9, 3, 'Hull-Casting Pits');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_high_hammer_9, 4, 'The Night Shift Barracks');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='asmecu'), 1, 'The Coral Palace', 'Devotion · Secrecy · Coral Crowns', 'Where the Sea Queen Listens', 'Domes of living coral curve above the water like a crown that never stops blooming, grown rather than built, at the will of the woman who calls this water hers. Here Lysara receives her petitioners one tide at a time, always alone, always smiling. No guard stands closer to her throne than the water itself — because the water listens too.', 'She calls us her tides. I have never decided if that''s a comfort or a warning.', 'Anonymous petitioner', 'orange');
SET @layer_asmecu_1 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_1, 1, 'The Sea Queen''s Throne');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_1, 2, 'The Coral Reliquary');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_1, 3, 'The Listening Pools');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_1, 4, 'Petitioners'' Stair');
INSERT INTO world_landmarks (world_id, layer_id, sort_order, kind, name, tag_label, description, quote_text, quote_cite) VALUES ((SELECT id FROM worlds WHERE slug='asmecu'), @layer_asmecu_1, 1, 'restricted', 'The Abyss', 'Marked in Every Chart With a Warning', 'Every navigational chart in Asmecu carries the same symbol past its northeastern waters: a spiral, and a warning no sailor needs translated twice. The Abyss is the one place Lysara has never claimed to rule — and the one place her Tideguard will turn a ship around without explanation, apology, or exception.', 'She built her palace on its eye. I have never once heard her explain why.', 'Codex note, Asmecu entry');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='asmecu'), 2, 'Royal Harbor', 'Ceremony · Naval Guard · First Arrivals', 'The Queen''s Own Waters', 'Every dignitary, merchant envoy, and hopeful petitioner passes beneath the Royal Harbor''s arches before they ever see the Palace itself. Lysara''s Tideguard patrol these waters in coral-hulled skiffs, polite to a fault, and utterly without mercy toward anyone who lingers past dusk.', 'They saluted us with open hands and closed eyes. I have never trusted a harbor more, or less.', 'Foreign trade envoy''s journal', 'teal');
SET @layer_asmecu_2 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_2, 1, 'The Arrival Arches');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_2, 2, 'Tideguard Barracks');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_2, 3, 'The Envoy''s Wharf');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_2, 4, 'Ceremonial Skiffs');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='asmecu'), 3, 'The Market', 'Commerce · Gossip · A Hundred Currents', 'Where Every Current Meets', 'Striped awnings ring the plaza in every color the dye-boats can carry, and the crowd never fully quiets, day or night. This is Asmecu at its most human — bartering, gossiping, matchmaking — a single circle of noise built directly atop water Lysara has never let anyone chart.', 'Ask the fishmonger for directions and she''ll draw you a map of everything except what''s underneath us.', 'Market visitor''s note', 'gold');
SET @layer_asmecu_3 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_3, 1, 'The Floating Stalls');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_3, 2, 'Dye-Boat Row');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_3, 3, 'The Fountain Court');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_3, 4, 'Matchmakers'' Circle');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='asmecu'), 4, 'Merchant Republic', 'Guilds · Ledgers · Quiet Fortunes', 'Where Coin Answers to the Crown', 'Asmecu''s guildhouses cluster here, wealthy enough to almost forget they answer to a queen. Almost. Every ledger, every contract, every whispered deal still crosses Lysara''s desk eventually — she has simply made it so pleasant to do business that no one minds being watched.', 'She lets us keep our fortunes. She just never lets us forget whose sea they float on.', 'Guildmaster, private correspondence', 'green');
SET @layer_asmecu_4 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_4, 1, 'The Ledger Halls');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_4, 2, 'Guild Exchange');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_4, 3, 'Underwriters'' Row');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_4, 4, 'The Silent Auction');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='asmecu'), 5, 'Canal District', 'Community · Gondoliers · No Locked Doors', 'The City Between the Streets', 'Homes rise on stilts and coral pilings, connected by a lattice of narrow canals where gondoliers know every family''s business better than the family does. It is the most crowded part of Asmecu, and — citizens insist — the safest, because nothing moves through these waters without a dozen witnesses.', 'We don''t lock our doors. The canals do that for us.', 'Canal District saying', 'teal');
SET @layer_asmecu_5 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_5, 1, 'The Stilted Rows');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_5, 2, 'Gondoliers'' Guild');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_5, 3, 'Bridge of a Hundred Steps');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_5, 4, 'Laundry Canals');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='asmecu'), 6, 'Great Lighthouse', 'Boundary · Storm-Warning · Watched Waters', 'Where the Safe Water Ends', 'Taller than the Palace itself, the Great Lighthouse marks the last charted boundary of Asmecu''s waters — beyond its beam, sailors are on their own. Its keepers are chosen by Lysara personally, and none has ever spoken publicly about what they watch for on the nights the light burns a color that isn''t fire.', 'The light isn''t for ships. Not most nights.', 'Retired lighthouse keeper, unconfirmed', 'gold');
SET @layer_asmecu_6 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_6, 1, 'The Keeper''s Spiral');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_6, 2, 'The Beacon Chamber');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_6, 3, 'Storm-Warning Bells');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_6, 4, 'The Boundary Charts');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='asmecu'), 7, 'Temple of the Ocean', 'Ritual · Offerings · What Waits Below', 'Where the Tides Are Prayed To', 'Steps descend straight into open water at the Temple of the Ocean, where priestesses conduct rites no outsider has ever been permitted to witness in full. Citizens leave offerings at the tideline every dawn — not to Lysara, they''re careful to specify, but to whatever she answers to.', 'We do not pray to our Queen. We pray to what waits below her, and hope she stays between us.', 'Temple offering-day chant, fragment', 'teal');
SET @layer_asmecu_7 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_7, 1, 'The Tideline Steps');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_7, 2, 'Priestesses'' Cloister');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_7, 3, 'The Offering Shoals');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_asmecu_7, 4, 'The Undersong Bell');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='cerius'), 1, 'The Iron Gate', 'Arrival · Inspection · No Return', 'Where Cerius Begins', 'Every laborer, prisoner, and shipment enters Cerius through this one fortified gate, and the Regent''s clerks record all three the same way: as inventory. The gate has opened for thirty-five years without ever once closing early, a fact the Black Regent''s banners make sure no one forgets.', 'They count you twice at the Gate. Once as a person. Once as a number. Only the second count follows you inside.', 'New arrival''s account, since redacted', 'orange');
SET @layer_cerius_1 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_1, 1, 'The Iron Stockade');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_1, 2, 'Inspection Yards');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_1, 3, 'The Ledger House');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='cerius'), 2, 'The Old Forum', 'Memory · Ruin · What Democracy Left Behind', 'What''s Left of the Republic', 'Before the coup, this square was where Cerius argued with itself and called it government. The columns still stand, chipped and soot-stained, because Malric decided a ruin makes a better lesson than rubble ever could. Nobody gathers here anymore. That''s rather the point.', 'He didn''t tear it down. He let us keep looking at it.', 'Cerius resident, generations removed from the coup', 'slate');
SET @layer_cerius_2 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_2, 1, 'The Broken Assembly');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_2, 2, 'Petitioners'' Steps');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_2, 3, 'The Toppled Statues');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='cerius'), 3, 'The Smelter Rings', 'Ore · Fire · Endless Shifts', 'Where the Ore Never Cools', 'Concentric rings of furnaces ring the western districts, running in shifts that never fully stop — the fires are only ever banked, never doused. B62X is the oldest and largest of them, older than the coup itself, and still the measure every other furnace district is quota-graded against.', 'The furnace doesn''t know it''s midnight. Neither do we, most weeks.', 'Smelter Rings shift log, unsigned', 'red');
SET @layer_cerius_3 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_3, 1, 'B62X Furnace District');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_3, 2, 'Ore Conveyors');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_3, 3, 'Shift-Whistle Towers');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_3, 4, 'The Slag Pits');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='cerius'), 4, 'The Forge Canals', 'Molten Transit · The City''s Bloodstream', 'The River That Isn''t Water', 'Cooled slow enough to move but never enough to touch, the Forge Canals carry molten runoff clear across the city on barges built to outlast the men who crew them. Ashline Barracks lines the near bank, close enough that the barge crews'' children grow up thinking the glow is just what evening looks like.', 'You get used to the light. You never get used to the heat.', 'Barge crewman, Ashline Barracks', 'orange');
SET @layer_cerius_4 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_4, 1, 'Ashline Barracks');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_4, 2, 'Cooling Locks');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_4, 3, 'Barge Docks');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='cerius'), 5, 'The Iron Yards', 'Labor · Machinery · Production Quotas', 'Where the Quota Is Law', 'Assembly floors stretch for what feels like miles, worked in rotations tracked down to the minute by the Quota Halls next door. Every worker here has a number that matters more to the Regent''s ledgers than their name does — and every one of them knows exactly where that number ranks them.', 'Meet the quota, you''re a worker. Miss it twice, you''re a lesson.', 'Iron Yards floor supervisor, overheard', 'slate');
SET @layer_cerius_5 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_5, 1, 'The Worker Barracks');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_5, 2, 'The Quota Halls');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_5, 3, 'Assembly Lines');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='cerius'), 6, 'The Docking Spires', 'Cargo · Surveillance · Departure', 'The Last Thing Cerius Sees You Do', 'Cargo haulers and Regent-flagged gunships share the same searchlit towers, and nothing leaves Cerius airspace without a Spire crew logging it twice. Officially, the Spires exist to move iron off-world. Unofficially, everyone below knows they also exist to make sure nothing — and no one — leaves without permission.', 'The lights never stop moving. Neither do the ships they''re watching.', 'Docking Spires ground crew', 'slate');
SET @layer_cerius_6 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_6, 1, 'Cargo Cranes');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_6, 2, 'Surveillance Towers');
INSERT INTO world_landmarks (world_id, layer_id, sort_order, kind, name, tag_label, description, quote_text, quote_cite) VALUES ((SELECT id FROM worlds WHERE slug='cerius'), @layer_cerius_6, 1, 'restricted', 'Prison Camp Z92-R', 'Marked, Guarded, Never Discussed', 'Visible from the Spires if the searchlights swing the wrong way, Z92-R holds the prisoners Malric considers worth the airspace to keep close — not exiled, not executed, just kept where his ledgers can always account for them. No sentence handed down here has ever had a public end date.', 'Z92-R isn''t a secret. It''s a reminder. Those aren''t the same thing, but he likes that we confuse them.', 'Former Cerius clerk, defected');
INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key) VALUES ((SELECT id FROM worlds WHERE slug='cerius'), 7, 'The Black Regent''s Keep', 'Command · Order · Absolute Rule', 'Where the Arithmetic Is Written', 'Every quota, every ledger, every red banner in Cerius traces back to this fortress in the mountains, where Malric Thorne has ruled alone since the night he ended a democracy and never once looked back to check the cost. He is said to review every district''s numbers personally, every week, without exception — and to remember every deficit long after the worker who caused it is gone.', 'He calls it the arithmetic of suffering. I have never heard him call it anything else, and I have never once seen the sum come out in our favor.', 'Court record, Cerius archive', 'red');
SET @layer_cerius_7 = LAST_INSERT_ID();
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_7, 1, 'The Regent''s Court');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_7, 2, 'The War Room');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_7, 3, 'The Long Stair');
INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (@layer_cerius_7, 4, 'Red Banner Hall');

-- World-level "distant" landmarks (not nested in any layer)

INSERT INTO world_landmarks (world_id, layer_id, sort_order, kind, name, tag_label, description, quote_text, quote_cite) VALUES ((SELECT id FROM worlds WHERE slug='high-hammer'), NULL, 1, 'distant', 'Lios', 'Beyond the Iron Mountains', 'No official chart of High Hammer shows it, but smuggled ones do — a name scratched past the peaks, with an arrow and nothing else. Miners trade rumors of lights in the high passes at night; sailors swap stories from crews who claim to have seen it from the air and were never quite believed again. Maerion Thal has never confirmed or denied it exists. He simply changes the subject.', 'Ask him about Lios and watch the Sky Duke smile like a man changing the weather.', 'Court gossip, unverified');
INSERT INTO world_landmarks (world_id, layer_id, sort_order, kind, name, tag_label, description, quote_text, quote_cite) VALUES ((SELECT id FROM worlds WHERE slug='asmecu'), NULL, 1, 'distant', 'The Endless Sea', 'Beyond Every Chart Asmecu Keeps', 'Past the Great Lighthouse, past even the shipping lanes swept by Lysara''s Tideguard, the water simply continues — the Endless Sea, uncharted and, by the Queen''s own decree, untraveled. No law forbids sailing into it. No sailor who has ever done so has come back to explain why they turned around.', 'I asked her what was past the Lighthouse. She smiled like the tide and changed the subject.', 'Foreign trade envoy''s journal');
