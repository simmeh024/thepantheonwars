<?php
/**
 * "Twelve Worlds at a Glance" strip on worlds.html, and the header weather
 * widget: current condition + temp for every world that is both World Control
 * `available` and has an enabled weather profile, in one query rather than one
 * api/world-weather.php request per world (the same N+1-avoidance principle
 * already used by api/worlds.php and api/boards-summary.php).
 *
 * This set is also exactly the widget's "unlocked worlds" picker list, so it
 * needs no separate endpoint of its own.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/weather-forecast.php';

$db = pw_db();

// worlds.accent_rgb arrives with sql/migration_weather_widget.sql, and
// current_auto/tomorrow_auto with sql/migration_weather_auto_forecast.sql. A
// missing column is a hard SQL error rather than a NULL, so both are selected
// separately and independently -- either migration can land before the other,
// or not yet at all, and this still returns a usable row either way.
$accentColumn = ', w.accent_rgb';
$autoColumns = ', p.current_auto, p.tomorrow_auto';
$baseSelect =
    'SELECT w.slug, w.name, w.sort_order%s,
            p.location_label, p.climate_label,
            p.current_condition, p.current_secondary, p.current_temp_c,
            p.tomorrow_condition, p.tomorrow_temp_c%s,
            p.forecast_min_c, p.forecast_max_c,
            p.humidity_min, p.humidity_max,
            p.precipitation_min, p.precipitation_max,
            p.wind_min_kph, p.wind_max_kph,
            p.condition_pool_json, p.hazard_note, p.forecast_revision
     FROM worlds w
     JOIN world_weather_profiles p ON p.world_id = w.id
     WHERE w.status = \'available\' AND p.enabled = 1
     ORDER BY w.sort_order ASC';

$rows = null;
foreach ([[$accentColumn, $autoColumns], ['', $autoColumns], [$accentColumn, ''], ['', '']] as $combo) {
    try {
        $rows = $db->query(sprintf($baseSelect, $combo[0], $combo[1]))->fetchAll();
        break;
    } catch (PDOException $e) {
        continue;
    }
}
$rows = $rows === null ? [] : $rows;

$worlds = array_map(function ($row) {
    $forecast = pw_build_weather_forecast($row, $row['slug']);
    return [
        'slug' => $row['slug'],
        'name' => $row['name'],
        'location' => $forecast['location'],
        // Bare "R, G, B" components, not a CSS colour, so one value drives both
        // a solid fill and a translucent glow. Empty until the migration runs.
        'accent' => isset($row['accent_rgb']) ? (string)$row['accent_rgb'] : '',
        'current' => $forecast['current'],
    ];
}, $rows);

pw_json(['ok' => true, 'worlds' => $worlds]);
