<?php
/** Public fictional weather feed for an available World Record. */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/weather-forecast.php';

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
if (!preg_match('/^[a-z0-9-]{1,50}$/', $slug)) {
    pw_error('Choose a valid world.', 400);
}

$db = pw_db();
// current_auto/tomorrow_auto arrive with sql/migration_weather_auto_forecast.sql.
// Selected separately since a missing column is a hard SQL error rather than a
// NULL; falling back to the pre-migration column list means every world's
// weather simply keeps reading as fully authored until the migration runs.
$autoColumns = ', p.current_auto, p.tomorrow_auto';
$baseSelect =
    'SELECT w.slug, w.status,
            p.enabled, p.location_label, p.climate_label,
            p.current_condition, p.current_secondary, p.current_temp_c,
            p.tomorrow_condition, p.tomorrow_temp_c%s,
            p.forecast_min_c, p.forecast_max_c,
            p.humidity_min, p.humidity_max,
            p.precipitation_min, p.precipitation_max,
            p.wind_min_kph, p.wind_max_kph,
            p.condition_pool_json, p.hazard_note, p.forecast_revision
     FROM worlds w
     LEFT JOIN world_weather_profiles p ON p.world_id = w.id
     WHERE w.slug = ?';
try {
    $stmt = $db->prepare(sprintf($baseSelect, $autoColumns));
    $stmt->execute([$slug]);
    $profile = $stmt->fetch();
} catch (PDOException $e) {
    $stmt = $db->prepare(sprintf($baseSelect, ''));
    $stmt->execute([$slug]);
    $profile = $stmt->fetch();
}

if (!$profile) {
    pw_error('World not found.', 404);
}
if ($profile['status'] !== 'available' || $profile['enabled'] === null || (int)$profile['enabled'] !== 1) {
    pw_json(['ok' => true, 'available' => false]);
}

pw_json([
    'ok' => true,
    'available' => true,
    // With hours: the World Record's five-day strip opens an hourly panel per
    // day, and carrying them in this one request avoids a fetch on every hover.
    'weather' => pw_build_weather_forecast($profile, $slug, null, true),
]);
