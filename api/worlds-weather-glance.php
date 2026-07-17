<?php
/**
 * "Twelve Worlds at a Glance" strip on worlds.html: current condition + temp
 * for every world that is both World Control `available` and has an enabled
 * weather profile, in one query rather than one api/world-weather.php
 * request per world (the same N+1-avoidance principle already used by
 * api/worlds.php and api/boards-summary.php).
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/weather-forecast.php';

$db = pw_db();
$rows = $db->query(
    'SELECT w.slug, w.name, w.sort_order,
            p.location_label, p.climate_label,
            p.current_condition, p.current_secondary, p.current_temp_c,
            p.tomorrow_condition, p.tomorrow_temp_c,
            p.forecast_min_c, p.forecast_max_c,
            p.humidity_min, p.humidity_max,
            p.precipitation_min, p.precipitation_max,
            p.wind_min_kph, p.wind_max_kph,
            p.condition_pool_json, p.hazard_note, p.forecast_revision
     FROM worlds w
     JOIN world_weather_profiles p ON p.world_id = w.id
     WHERE w.status = \'available\' AND p.enabled = 1
     ORDER BY w.sort_order ASC'
)->fetchAll();

$worlds = array_map(function ($row) {
    $forecast = pw_build_weather_forecast($row, $row['slug']);
    return [
        'slug' => $row['slug'],
        'name' => $row['name'],
        'location' => $forecast['location'],
        'current' => $forecast['current'],
    ];
}, $rows);

pw_json(['ok' => true, 'worlds' => $worlds]);
