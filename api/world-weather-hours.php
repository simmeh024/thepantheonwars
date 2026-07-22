<?php
/**
 * Rolling twelve-hour projection for one world, from the current UTC hour.
 *
 * Feeds the header weather pill's hover panel. Separate from
 * api/worlds-weather-glance.php on purpose: that endpoint serves every
 * available world at once for the pill and the twelve-world strip, and folding
 * twelve hourly rows into each of them would multiply a payload that is
 * otherwise only current conditions. This is fetched once, for the one world
 * the visitor actually has selected, and only when they hover.
 *
 * Values come from the same generator as the World Record's hourly panel, so
 * the two surfaces always agree on an hour they both show.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/weather-forecast.php';

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
if (!preg_match('/^[a-z0-9-]{1,50}$/', $slug)) {
    pw_error('Choose a valid world.', 400);
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT w.slug, w.name, w.status,
            p.enabled, p.location_label, p.climate_label,
            p.current_condition, p.current_secondary, p.current_temp_c,
            p.tomorrow_condition, p.tomorrow_temp_c,
            p.forecast_min_c, p.forecast_max_c,
            p.humidity_min, p.humidity_max,
            p.precipitation_min, p.precipitation_max,
            p.wind_min_kph, p.wind_max_kph,
            p.condition_pool_json, p.hazard_note, p.forecast_revision
     FROM worlds w
     LEFT JOIN world_weather_profiles p ON p.world_id = w.id
     WHERE w.slug = ?'
);
$stmt->execute([$slug]);
$profile = $stmt->fetch();

if (!$profile) {
    pw_error('World not found.', 404);
}
// Same availability gate as every other public weather read: a locked world or
// a disabled profile returns nothing rather than leaking a sealed record.
if ($profile['status'] !== 'available' || $profile['enabled'] === null || (int)$profile['enabled'] !== 1) {
    pw_json(['ok' => true, 'available' => false]);
}

$forecast = pw_build_weather_forecast($profile, $slug);
$timezone = new DateTimeZone('UTC');
$today = new DateTimeImmutable('today', $timezone);
$nowHour = (int)(new DateTimeImmutable('now', $timezone))->format('G');

pw_json([
    'ok' => true,
    'available' => true,
    'slug' => $slug,
    'name' => (string)$profile['name'],
    'timezone' => 'UTC',
    'now_hour' => $nowHour,
    'hours' => pw_weather_rolling_hours(
        $profile,
        $slug,
        $today,
        $forecast['forecast'],
        $nowHour,
        max(1, (int)$profile['forecast_revision']),
        12
    ),
]);
