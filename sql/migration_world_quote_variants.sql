-- Weather-varying pull quotes for World Record districts.
--
-- Run once in phpMyAdmin after deploying the accompanying code. Idempotent, so
-- a repeated run is safe. Nothing is required for the site to keep working:
-- a layer with no variants simply keeps showing its existing quote_text, so
-- these can be written one at a time.

-- ---------------------------------------------------------------------------
-- Keyed by the five condition icons the weather system already speaks
-- (acid-rain, storm, smog, clear, overcast) rather than by each world's own
-- condition names. Those names live in condition_pool_json and can be edited,
-- which would orphan any quote keyed to the old wording; the icon keys are
-- fixed vocabulary and every world shares them.
--
-- entity_type is polymorphic so landmarks can be given the same treatment later
-- without a second table or migration. That also means no foreign key is
-- possible -- a row cannot reference two different tables -- so
-- api/admin/worlds/layers/delete.php clears a layer's variants explicitly.
-- Without that, a recycled AUTO_INCREMENT id would hand a future layer someone
-- else's quotes, the same hazard the timeline discovery rows have.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS world_quote_variants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('layer','landmark') NOT NULL DEFAULT 'layer',
  entity_id INT UNSIGNED NOT NULL,
  condition_key VARCHAR(20) NOT NULL,
  quote_text VARCHAR(400) NOT NULL,
  quote_cite VARCHAR(150) NOT NULL DEFAULT '',
  UNIQUE KEY uq_world_quote_variant (entity_type, entity_id, condition_key),
  KEY idx_world_quote_variant_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
