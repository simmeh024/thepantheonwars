-- Neoh weather-system pilot.
-- Run once in phpMyAdmin after deploying the accompanying application code.

CREATE TABLE IF NOT EXISTS world_weather_profiles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_id INT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  location_label VARCHAR(120) NOT NULL DEFAULT '',
  climate_label VARCHAR(160) NOT NULL DEFAULT '',
  current_condition VARCHAR(80) NOT NULL DEFAULT '',
  current_secondary VARCHAR(120) NOT NULL DEFAULT '',
  current_temp_c SMALLINT NOT NULL DEFAULT 0,
  tomorrow_condition VARCHAR(80) NOT NULL DEFAULT '',
  tomorrow_temp_c SMALLINT NOT NULL DEFAULT 0,
  forecast_min_c SMALLINT NOT NULL DEFAULT -10,
  forecast_max_c SMALLINT NOT NULL DEFAULT 30,
  humidity_min TINYINT UNSIGNED NOT NULL DEFAULT 40,
  humidity_max TINYINT UNSIGNED NOT NULL DEFAULT 90,
  precipitation_min TINYINT UNSIGNED NOT NULL DEFAULT 0,
  precipitation_max TINYINT UNSIGNED NOT NULL DEFAULT 100,
  wind_min_kph SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  wind_max_kph SMALLINT UNSIGNED NOT NULL DEFAULT 80,
  condition_pool_json TEXT NOT NULL,
  hazard_note VARCHAR(255) NOT NULL DEFAULT '',
  forecast_revision INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_world_weather_world (world_id),
  CONSTRAINT fk_world_weather_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
  CONSTRAINT fk_world_weather_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('weather.view', 'View Weather Control', 'Lore Management'),
  ('weather.edit', 'Edit world weather profiles', 'Lore Management')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

INSERT INTO world_weather_profiles (
  world_id, enabled, location_label, climate_label,
  current_condition, current_secondary, current_temp_c,
  tomorrow_condition, tomorrow_temp_c,
  forecast_min_c, forecast_max_c,
  humidity_min, humidity_max,
  precipitation_min, precipitation_max,
  wind_min_kph, wind_max_kph,
  condition_pool_json, hazard_note
)
SELECT
  id, 1, 'Neoh Central Archive', 'Industrial acid-rain basin',
  'Acid rain', 'Dense smog', 19,
  'Smog', 16,
  10, 21,
  72, 96,
  35, 95,
  8, 34,
  '["Acid rain","Corrosive drizzle","Dense smog","Overcast haze","Static storm"]',
  'Corrosive rainfall. Unfiltered exposure is not advised.'
FROM worlds
WHERE slug = 'neoh'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);
