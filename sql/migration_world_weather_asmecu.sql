-- Asmecu weather-system extension (second calibration world, after Neoh).
-- Run once in phpMyAdmin after deploying the accompanying application code.
-- Reuses world_weather_profiles / weather.view / weather.edit exactly as-is
-- (both already created by migration_world_weather.sql) -- this only seeds
-- a second profile row so Weather Control's now-generic admin UI has a
-- second collapsible world to show alongside Neoh.

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
  id, 1, 'Asmecu Harbor Watch', 'Tropical tidal archipelago',
  'Salt fog', 'Slack low tide', 27,
  'Tidal storm', 24,
  22, 31,
  68, 94,
  15, 75,
  6, 48,
  '["Salt fog","Clear tide","Tidal storm","Sea drizzle","Overcast swell","Humid haze"]',
  'Undertow advisory along the Abyssal Chart line. Small craft should hold to marked channels until the surge passes.'
FROM worlds
WHERE slug = 'asmecu'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);
