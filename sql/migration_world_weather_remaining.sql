-- Weather Control rollout to every remaining calibrated world except The
-- Nexus Veil (neutral ground, no per-medallion atlas motif, deliberately
-- excluded). Reuses world_weather_profiles / weather.view / weather.edit
-- exactly as-is (already created by migration_world_weather.sql) -- this
-- only seeds one profile row per world so each gets its own collapsible
-- section in the now-generic Weather Control admin UI. Locked worlds are
-- seeded the same as available ones: api/world-weather.php only serves a
-- profile once its World Control record is `available`, so a locked world's
-- profile stays inert (editable in the admin console, invisible publicly)
-- until it is unlocked -- exactly like every other locked world's lore data.
-- Run once in phpMyAdmin after deploying the accompanying application code.

-- High Hammer -- "The Marble Heart of Industry" (available)
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
  id, 1, 'High Hammer Forge Watch', 'Marble highland forge belt',
  'Ash haze', 'Forge-warmed updrafts', 14,
  'Clear kiln-light', 17,
  6, 22,
  20, 55,
  0, 25,
  10, 50,
  '["Ash haze","Clear kiln-light","Ember drizzle","Static discharge storm","Overcast smoke banks","Molten haze"]',
  'Foundry vents periodically discharge ash and ember fallout. Petitioners are advised to keep the Marble Concourse covered during kiln shifts.'
FROM worlds
WHERE slug = 'high-hammer'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);

-- Cerius -- "The Throne Built on Smoke" (available)
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
  id, 1, 'Cerius Ember Quarter', 'Ash-veiled iron capital',
  'Ember haze', 'Drifting cinder', 21,
  'Smoldering overcast', 19,
  12, 29,
  25, 60,
  0, 30,
  5, 35,
  '["Ember haze","Smoldering overcast","Cinder drizzle","Static ash storm","Clear smoke-break","Choking smog"]',
  'Cinderfall from the old capital''s still-burning undercity drifts through the Ember Quarter on windless days. Sensitive lungs should stay indoors.'
FROM worlds
WHERE slug = 'cerius'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);

-- Reanium -- "The Wasteland That Remembers" (locked)
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
  id, 1, 'Reanium Glass Flats', 'Irradiated glassland wasteland',
  'Radiant haze', 'Glass-flat shimmer', 38,
  'Static storm', 41,
  30, 47,
  5, 20,
  0, 5,
  15, 60,
  '["Radiant haze","Static storm","Clear glass-flat glare","Ash-fall drizzle","Overcast fallout haze","Green fog"]',
  'Background radiation remains elevated across the Glass Flats. Surface excursions beyond the marked corridors are not advised.'
FROM worlds
WHERE slug = 'reanium'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);

-- Babki Prime -- "The Jungle That Feels Pain" (locked)
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
  id, 1, 'Babki Prime Canopy Line', 'Sentient rainforest canopy',
  'Canopy drizzle', 'Restless leaf-wind', 29,
  'Static thunder storm', 27,
  23, 33,
  75, 98,
  40, 90,
  8, 40,
  '["Canopy drizzle","Static thunder storm","Clear canopy break","Overcast humidity","Golden leaf-fall haze","Root-mist fog"]',
  'The canopy''s living weapons remain agitated after any thunder event. Off-path travel through Babki Prime is discouraged for at least a full cycle.'
FROM worlds
WHERE slug = 'babki-prime'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);

-- Sed -- "The Hellscape Under a Black Sun" (locked)
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
  id, 1, 'Sed Scorch Flats', 'Black-sun scorch basin',
  'Clear scorch glare', 'Cracked basin haze', 54,
  'Ash-storm', 57,
  46, 63,
  2, 12,
  0, 2,
  5, 45,
  '["Clear scorch glare","Ash-storm","Cracked-earth haze","Ember drizzle","Overcast cinder pall","Static heat storm"]',
  'Surface temperatures beneath the black sun exceed safe unshielded exposure limits by midday. Travel only within shaded transit corridors.'
FROM worlds
WHERE slug = 'sed'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);

-- Geof V -- "The March of Iron" (locked)
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
  id, 1, 'Geof V Iron Column', 'Steel-fog marching plains',
  'Steel fog', 'Iron-grey overcast', 4,
  'Sleet drizzle', 2,
  -6, 9,
  60, 92,
  30, 80,
  20, 70,
  '["Steel fog","Sleet drizzle","Static column storm","Clear iron-light","Overcast marching haze","Rust-rain"]',
  'Visibility across the Iron Column drops sharply in steel fog. The march does not halt for weather; stragglers are not recovered.'
FROM worlds
WHERE slug = 'geof-v'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);

-- Beoctica -- "The City Without Shadows" (locked)
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
  id, 1, 'Beoctica Frostline Spire', 'Shadowless frost capital',
  'Frost haze', 'Still, shadowless air', -8,
  'Clear frostlight', -11,
  -19, -3,
  45, 80,
  5, 35,
  0, 20,
  '["Frost haze","Clear frostlight","Static ice storm","Overcast twilight pall","Rime drizzle","Silent whiteout fog"]',
  'Beoctica''s permanent twilight makes frost accumulation difficult to judge by eye. Exposed skin should be covered regardless of apparent conditions.'
FROM worlds
WHERE slug = 'beoctica'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);

-- Terek II -- "The World That Never Stops Marching" (locked)
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
  id, 1, 'Terek II Forward Line', 'Perpetual war-front lowlands',
  'Smoke haze', 'Distant bombardment glow', 17,
  'Static barrage storm', 15,
  9, 24,
  30, 65,
  10, 50,
  10, 55,
  '["Smoke haze","Static barrage storm","Ember drizzle","Clear lull","Overcast ash pall","Cordite fog"]',
  'The front line has not gone quiet in living memory. Unscheduled bombardment can begin without warning at any hour.'
FROM worlds
WHERE slug = 'terek-ii'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);

-- Valerium Prime -- "The Desert of Three Moons" (locked)
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
  id, 1, 'Valerium Prime Threefold Basin', 'Triple-moon desert basin',
  'Clear triple-moon glow', 'Golden desert haze', 33,
  'Sandfall haze', 36,
  24, 44,
  8, 30,
  0, 10,
  10, 50,
  '["Clear triple-moon glow","Sandfall haze","Static dust storm","Golden overcast veil","Warm drizzle","Halo fog"]',
  'Triple-moon tides periodically strip visible sand cover from the Threefold Basin overnight. Marked paths may shift by morning.'
FROM worlds
WHERE slug = 'valerium-prime'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);

-- Vermillia XI -- "The Domes That Aren't for Protection" (locked)
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
  id, 1, 'Vermillia XI Dome Interior', 'Perpetual interior downpour',
  'Dome drizzle', 'Standing condensation', 19,
  'Static dome storm', 18,
  14, 23,
  85, 99,
  70, 100,
  0, 15,
  '["Dome drizzle","Static dome storm","Overcast condensation","Clear dome pause","Golden mist haze","Standing fog"]',
  'The domes have never stopped raining within living memory, by design rather than failure. Extended exposure without dome-rated gear is not recommended.'
FROM worlds
WHERE slug = 'vermillia-xi'
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);
