<?php
/**
 * Deterministic fictional weather generation shared by the public endpoint.
 * A world, UTC date, and saved revision always produce the same values, so a
 * page refresh cannot make the forecast jump while an administrator can force
 * a fresh sequence simply by saving the profile.
 */

function pw_weather_hash_int($seed) {
    return (int)hexdec(substr(hash('sha256', (string)$seed), 0, 7));
}

function pw_weather_range($seed, $minimum, $maximum) {
    $minimum = (int)$minimum;
    $maximum = (int)$maximum;
    if ($maximum <= $minimum) {
        return $minimum;
    }
    return $minimum + (pw_weather_hash_int($seed) % ($maximum - $minimum + 1));
}

function pw_weather_icon_key($condition) {
    $condition = strtolower((string)$condition);
    if (strpos($condition, 'storm') !== false || strpos($condition, 'static') !== false) return 'storm';
    if (strpos($condition, 'acid') !== false || strpos($condition, 'rain') !== false || strpos($condition, 'drizzle') !== false) return 'acid-rain';
    if (strpos($condition, 'smog') !== false || strpos($condition, 'haze') !== false || strpos($condition, 'fog') !== false) return 'smog';
    if (strpos($condition, 'sun') !== false || strpos($condition, 'clear') !== false) return 'clear';
    return 'overcast';
}

function pw_weather_conditions($profile) {
    $decoded = json_decode(isset($profile['condition_pool_json']) ? $profile['condition_pool_json'] : '[]', true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $conditions = [];
    foreach ($decoded as $condition) {
        $condition = trim((string)$condition);
        if ($condition !== '' && !in_array($condition, $conditions, true)) {
            $conditions[] = $condition;
        }
    }
    if (!$conditions) {
        $conditions[] = (string)$profile['current_condition'];
    }
    return $conditions;
}

function pw_weather_seasonal_bias($worldSlug, DateTimeImmutable $date) {
    // A smooth, deterministic -1..1 wave across the calendar year, independent
    // of forecast_revision (season is a climate pattern, not part of the
    // "reroll the dice" admin action). The per-world phase offset keeps every
    // world from warming/cooling on the same calendar day.
    $dayOfYear = (int)$date->format('z');
    $phaseOffsetDays = pw_weather_hash_int($worldSlug . '|season-phase') % 365;
    $phase = (($dayOfYear + $phaseOffsetDays) / 365) * 2 * M_PI;
    return sin($phase);
}

function pw_weather_precipitation($condition, $seed, $minimum, $maximum) {
    $conditionKey = strtolower((string)$condition);
    $value = pw_weather_range($seed, $minimum, $maximum);
    if (preg_match('/acid|rain|drizzle|storm/', $conditionKey)) {
        return max(65, $value);
    }
    if (preg_match('/smog|haze|fog/', $conditionKey)) {
        return min(48, $value);
    }
    return $value;
}

function pw_build_weather_forecast($profile, $worldSlug, $today = null) {
    $timezone = new DateTimeZone('UTC');
    if (!$today instanceof DateTimeImmutable) {
        $today = new DateTimeImmutable('today', $timezone);
    } else {
        $today = $today->setTimezone($timezone)->setTime(0, 0);
    }

    $revision = max(1, (int)$profile['forecast_revision']);
    $dateKey = $today->format('Y-m-d');
    $baseSeed = $worldSlug . '|' . $dateKey . '|r' . $revision;
    $conditions = pw_weather_conditions($profile);
    $forecast = [];

    for ($offset = 0; $offset < 5; $offset++) {
        $date = $today->modify('+' . $offset . ' days');
        $seed = $baseSeed . '|day-' . $offset;
        if ($offset === 0) {
            $condition = (string)$profile['current_condition'];
            $temperature = (int)$profile['current_temp_c'];
        } elseif ($offset === 1) {
            $condition = (string)$profile['tomorrow_condition'];
            $temperature = (int)$profile['tomorrow_temp_c'];
        } else {
            $condition = $conditions[pw_weather_range($seed . '|condition', 0, count($conditions) - 1)];
            $rawTemperature = pw_weather_range($seed . '|temperature', $profile['forecast_min_c'], $profile['forecast_max_c']);
            $seasonalBias = pw_weather_seasonal_bias($worldSlug, $date);
            $seasonalShift = (int)round($seasonalBias * ($profile['forecast_max_c'] - $profile['forecast_min_c']) * 0.15);
            $temperature = max($profile['forecast_min_c'], min($profile['forecast_max_c'], $rawTemperature + $seasonalShift));
        }

        $forecast[] = [
            'date' => $date->format('Y-m-d'),
            'day' => $offset === 0 ? 'Today' : ($offset === 1 ? 'Tomorrow' : $date->format('l')),
            'day_short' => $offset === 0 ? 'Today' : ($offset === 1 ? 'Tomorrow' : $date->format('D')),
            'condition' => $condition,
            'icon' => pw_weather_icon_key($condition),
            'temperature_c' => $temperature,
            // The configured/current value is the public daytime figure. This
            // keeps explicit story beats exact (Neoh is 19 C today and 16 C
            // tomorrow) instead of quietly adding a generated offset to them.
            'high_c' => $temperature,
            'low_c' => $temperature - pw_weather_range($seed . '|low', 3, 6),
            'humidity' => pw_weather_range($seed . '|humidity', $profile['humidity_min'], $profile['humidity_max']),
            'precipitation' => pw_weather_precipitation($condition, $seed . '|precipitation', $profile['precipitation_min'], $profile['precipitation_max']),
            'wind_kph' => pw_weather_range($seed . '|wind', $profile['wind_min_kph'], $profile['wind_max_kph']),
        ];
    }

    return [
        'generated_for' => $dateKey,
        'timezone' => 'UTC',
        'location' => (string)$profile['location_label'],
        'climate' => (string)$profile['climate_label'],
        'hazard_note' => (string)$profile['hazard_note'],
        'current' => [
            'condition' => (string)$profile['current_condition'],
            'secondary' => (string)$profile['current_secondary'],
            'icon' => pw_weather_icon_key($profile['current_condition']),
            'temperature_c' => (int)$profile['current_temp_c'],
            'feels_like_c' => (int)$profile['current_temp_c'] - pw_weather_range($baseSeed . '|feels', 1, 3),
            'humidity' => $forecast[0]['humidity'],
            'precipitation' => $forecast[0]['precipitation'],
            'wind_kph' => $forecast[0]['wind_kph'],
        ],
        'forecast' => $forecast,
    ];
}
