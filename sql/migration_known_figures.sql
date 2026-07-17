-- migration_known_figures.sql
-- Known Figures Control: known_figures table replacing the four hand-authored
-- <section class="figure-scene"> blocks in known-figures.html with a real,
-- admin-managed CRUD (Lore Management > Known Figures Control). Flat entity,
-- same list/modal/reorder pattern as Overlord Control (see migration_overlords.sql).
--
-- Each figure previously had a bespoke, hand-coded "signature" idle animation
-- (js/known-figures.js) and per-slug CSS color rules (content.css). Both are
-- now driven by two admin-editable fields instead of per-figure code: `motif`
-- picks one of the existing four hand-authored animation/veil-texture presets
-- (or "none"), and `accent_color` drives the eyebrow/glyph/portrait-border
-- color that used to be a hardcoded `.figure-scene--kael` etc. rule. This
-- keeps the existing four figures' look intact while letting new figures
-- reuse the same small preset library instead of requiring new JS/CSS.
--
-- Run by hand via phpMyAdmin's SQL tab, then fold into sql/schema.sql per
-- project convention.

CREATE TABLE IF NOT EXISTS known_figures (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  eyebrow VARCHAR(150) NOT NULL DEFAULT '',
  status_line VARCHAR(200) NOT NULL DEFAULT '',
  portrait_image_url VARCHAR(255) NOT NULL DEFAULT '',
  body_paragraph_1 TEXT NULL,
  body_paragraph_2 TEXT NULL,
  quote_text VARCHAR(400) NOT NULL DEFAULT '',
  quote_cite VARCHAR(150) NOT NULL DEFAULT '',
  accent_color VARCHAR(20) NOT NULL DEFAULT '#c7ccd6',
  motif ENUM('pulse','glitch','twirl','glint','none') NOT NULL DEFAULT 'none',
  signature_label VARCHAR(150) NOT NULL DEFAULT '',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('known_figures.view', 'View Known Figures Control', 'Lore Management'),
  ('known_figures.edit', 'Create/edit/reorder Known Figures', 'Lore Management'),
  ('known_figures.delete', 'Delete Known Figures', 'Lore Management')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

-- ============================================================
-- The 4 figures already live on known-figures.html, transcribed verbatim
-- from its static HTML so the cutover to the DB-backed page is visually
-- identical. accent_color: Kael and Brann reuse Neoh's atlas signal purple
-- and Brann's own red glitch signature respectively (the static page had a
-- Neoh-affiliation purple eyebrow but a red personal glyph/border for Brann;
-- collapsing to one color field keeps his own red signature, the stronger
-- and more consistent read across his glyph/border/veil/glitch-overlay).
-- VB and Teo reuse their own established portrait-derived teal and copper.
-- ============================================================

INSERT INTO known_figures (
  slug, name, eyebrow, status_line, portrait_image_url,
  body_paragraph_1, body_paragraph_2, quote_text, quote_cite,
  accent_color, motif, signature_label, is_published, sort_order
) VALUES
('kael', 'Kael Veyr', 'Neoh · The Mindweaver''s Lie', 'Status: at large, undercity of Neoh', 'images/char-kael.jpg',
 'He moves like a man who carries storms inside him — dark hair in a restless tangle, a face hardened by loss rather than age. A shard hangs at his chest, pulsing faintly with a light that seems to borrow its rhythm from his own heartbeat, a secret he never quite meant to keep.',
 'Shoulders tight as though bracing for a blow, steps carried with a strange defiance — every stride into the shadows another refusal to break. Kael Veyr does not look like a hero. He looks like someone the world already tried to bury, and failed.',
 'Every vault remembers who it was built to keep out. This one was built for me.', '— Kael Veyr, The Mindweaver''s Lie',
 '#9a60ee', 'pulse', 'A shard, kept close, kept warm', 1, 1),

('brann', 'Brann Ilex', 'Neoh · The Fractured Enforcer', 'Last confirmed act: rerouted Neoh undercity security, unconfirmed since', 'images/char-brann.jpg',
 'A former Ascendant Guard shocktrooper, discharged after the Helix Square Incident and quietly excised of the memory — though never the skill. He still wears his modified restraint collar like a badge of honor, and moves with the methodical precision of siege equipment built for exactly one purpose.',
 'He can''t remember which house he served, or why he still calls some men "sir" without irony. He can remember to leave protein packs for gutter kids who never see him do it. Somewhere in the wreckage of what Neoh made him, something in Brann Ilex never quite finished being scrapped.',
 'Run. I''ll hold.', '— Brann Ilex',
 '#dc3c3c', 'glitch', 'An obsolete token, rolled knuckle to knuckle', 1, 2),

('vb', 'VB', 'Origin unconfirmed · field alias "VB"', 'Status: active, location withheld', 'images/char-vb.jpg',
 'Known to most simply as VB, she radiates a wild, unpredictable energy that makes her both magnetic and unsettling — hair shaved close on one side and left to tumble on the other, streaked with the grime and neon of whatever street she''s currently claiming. A red implant gleams above her temple, pulsing like the beat of some hidden machine within.',
 'No noble, no soldier, no tyrant — VB is a street-born storm, a saboteur grinning through the sparks she starts on purpose. The danger was never the tool in her hand. It was always her delight in using it.',
 'Relax. If I wanted you dead, you''d already be sparking.', '— VB',
 '#46bebe', 'twirl', 'A tool, spinning idle between two fingers', 1, 3),

('teo', 'Teo Carnicus', 'High Hammer · The Brass Forge', 'Status: active, moves between forges and markets', 'images/char-teo.jpg',
 'He carries himself with a rogue''s ease — lean-faced, tousled, a pair of wire-framed glasses giving him an air of harmless intellect that the glint behind them never quite backs up. Nine books of collecting curiosities, relics, other people''s secrets. In the tenth, the collecting stops being academic.',
 'Not imposing like his kin, not regal in bearing — Teo Carnicus thrives in shadows and markets, a trickster who can smile while drawing blood, and rarely needs to raise his voice to do it.',
 'Everyone thinks the dangerous part''s the knife. It''s never the knife.', '— Teo Carnicus',
 '#e69650', 'glint', 'A blade, held loose, without hesitation', 1, 4);
