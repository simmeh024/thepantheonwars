-- Header weather widget: a compact bar in the site header showing one world's
-- current conditions, pointable at any unlocked world.
--
-- Run once in phpMyAdmin after deploying the accompanying code. Every statement
-- is idempotent (IF NOT EXISTS / blank-only UPDATE), so a partial or repeated
-- run is safe. Deploy order is not load-bearing: the reader in
-- api/session-check.php and the widget's own fallbacks both degrade to the
-- default world until this has been run.

-- ---------------------------------------------------------------------------
-- 1. Which world a member has pointed the widget at.
--
-- NULL means "never chosen", which the widget renders as its default (Neoh).
-- Deliberately not a foreign key to worlds(slug): a world being renamed or
-- removed must quietly fall back to the default rather than fail a write, and
-- the widget already validates the slug against the live available set on every
-- render. Signed-out visitors keep this in localStorage instead.
-- ---------------------------------------------------------------------------

ALTER TABLE users ADD COLUMN IF NOT EXISTS weather_world_slug VARCHAR(50) NULL;

-- ---------------------------------------------------------------------------
-- 2. Each world's signal colour.
--
-- Stored as bare "R, G, B" components rather than a CSS colour so one value
-- serves both a solid fill and a translucent glow -- the same --node-accent
-- convention the timeline markers already use:
--     rgb(var(--accent))  /  rgba(var(--accent), 0.22)
--
-- The twelve values below are the established per-world signal colours already
-- used by the Worlds atlas (ATLAS_TONES in js/worlds.js) and the footer's
-- top-edge strip. They are seeded here rather than invented so the header
-- widget reuses each world's existing identity instead of adding a third
-- hardcoded palette. ATLAS_TONES itself deliberately stays in JS: it drives the
-- atlas canvas effects and must render before any fetch.
--
-- The Nexus Veil is intentionally absent -- it is neutral ground with no
-- medallion motif and no weather profile, so it never reaches the widget.
--
-- Seeded only where blank, so re-running never overwrites an edited colour.
-- ---------------------------------------------------------------------------

ALTER TABLE worlds ADD COLUMN IF NOT EXISTS accent_rgb VARCHAR(20) NOT NULL DEFAULT '';

UPDATE worlds SET accent_rgb = '154, 96, 238'  WHERE slug = 'neoh'            AND accent_rgb = '';
UPDATE worlds SET accent_rgb = '184, 111, 66'  WHERE slug = 'high-hammer'     AND accent_rgb = '';
UPDATE worlds SET accent_rgb = '204, 72, 80'   WHERE slug = 'cerius'          AND accent_rgb = '';
UPDATE worlds SET accent_rgb = '159, 224, 65'  WHERE slug = 'reanium'         AND accent_rgb = '';
UPDATE worlds SET accent_rgb = '68, 150, 237'  WHERE slug = 'asmecu'          AND accent_rgb = '';
UPDATE worlds SET accent_rgb = '59, 148, 83'   WHERE slug = 'babki-prime'     AND accent_rgb = '';
UPDATE worlds SET accent_rgb = '166, 36, 57'   WHERE slug = 'sed'             AND accent_rgb = '';
UPDATE worlds SET accent_rgb = '158, 175, 193' WHERE slug = 'geof-v'          AND accent_rgb = '';
UPDATE worlds SET accent_rgb = '225, 232, 241' WHERE slug = 'beoctica'        AND accent_rgb = '';
UPDATE worlds SET accent_rgb = '121, 29, 40'   WHERE slug = 'terek-ii'        AND accent_rgb = '';
UPDATE worlds SET accent_rgb = '218, 176, 76'  WHERE slug = 'valerium-prime'  AND accent_rgb = '';
UPDATE worlds SET accent_rgb = '210, 142, 72'  WHERE slug = 'vermillia-xi'    AND accent_rgb = '';
