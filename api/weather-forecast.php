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

/**
 * One day's hourly readings, as [{hour, label, condition, icon, temperature_c}].
 *
 * Times are UTC throughout, matching how the whole forecast is generated. That
 * is deliberate: an hourly card resolved in the visitor's own zone would sit
 * under a day card whose boundaries are UTC, and the two would disagree about
 * which hours belong to "today".
 *
 * Temperature follows a diurnal curve between the day's own low_c and high_c
 * and is clamped to them, so the hourly panel can never contradict the daily
 * card above it. Day 0's curve peaks at the current hour rather than
 * mid-afternoon, because for day 0 high_c IS the administrator's authored
 * "right now" temperature -- so peaking at the current hour makes the hourly
 * row for now agree exactly with the big current-conditions figure.
 *
 * @param int $fromHour  First hour to emit. Day 0 passes the current hour so
 *                       elapsed hours are dropped; other days pass 0.
 */
function pw_weather_day_hours($profile, $worldSlug, DateTimeImmutable $date, $dayOffset, array $day, $revision, $nowHour, $fromHour = 0) {
    $high = (int)$day['high_c'];
    $low = (int)$day['low_c'];
    if ($low > $high) {
        $low = $high;
    }
    $mid = ($high + $low) / 2;
    $amplitude = ($high - $low) / 2;
    $peakHour = $dayOffset === 0 ? (int)$nowHour : 15;

    $conditions = pw_weather_conditions($profile);
    $headline = (string)$day['condition'];
    $dateKey = $date->format('Y-m-d');
    $hours = [];

    for ($hour = max(0, (int)$fromHour); $hour < 24; $hour++) {
        $seed = $worldSlug . '|' . $dateKey . '|r' . $revision . '|hour-' . $hour;

        // How far into the five-day window this hour sits. Deviation widens
        // with it, so a projection genuinely loosens the further out it
        // reaches. Measured from the start of day 0 rather than from "now", so
        // a given hour keeps the same value all day instead of shifting under
        // the reader every time the clock ticks over.
        $windowIndex = ($dayOffset * 24) + $hour;

        $curve = $mid + ($amplitude * cos(2 * M_PI * ($hour - $peakHour) / 24));
        $spread = min(5, 1 + (int)floor($windowIndex / 16));
        $temperature = (int)round($curve) + pw_weather_range($seed . '|jitter', -$spread, $spread);
        $temperature = max($low, min($high, $temperature));

        // The row for the current hour must read exactly what the big
        // current-conditions figure says, so it takes the administrator's
        // authored value directly. The curve already peaks here, but jitter
        // would otherwise pull it a degree or two under on some hours.
        if ($dayOffset === 0 && $hour === (int)$nowHour) {
            $temperature = $high;
        }

        // The day's headline condition dominates; deviations become more likely
        // further out, and are drawn from the world's own condition pool.
        $deviationChance = min(45, 10 + (int)floor($windowIndex * 0.6));
        $condition = $headline;
        if (count($conditions) > 1 && pw_weather_range($seed . '|deviates', 0, 99) < $deviationChance) {
            $condition = $conditions[pw_weather_range($seed . '|condition', 0, count($conditions) - 1)];
        }

        $hours[] = [
            'hour' => $hour,
            'label' => sprintf('%02d:00', $hour),
            'short_label' => (string)$hour,
            'condition' => $condition,
            'icon' => pw_weather_icon_key($condition),
            'temperature_c' => $temperature,
            // Driven by this hour's own condition rather than the day's, so a
            // dry hour inside a wet day reads as dry. pw_weather_precipitation()
            // already floors rain-like conditions and caps hazy ones.
            'precipitation' => pw_weather_precipitation(
                $condition,
                $seed . '|precipitation',
                $profile['precipitation_min'],
                $profile['precipitation_max']
            ),
        ];
    }

    return $hours;
}

/**
 * A rolling projection of the next $count hours from the current UTC hour,
 * crossing midnight into the following day.
 *
 * Values come from the same per-day series the forecast panel uses, so the two
 * surfaces can never disagree about an hour they both show.
 */
function pw_weather_rolling_hours($profile, $worldSlug, DateTimeImmutable $today, array $forecast, $nowHour, $revision, $count = 12) {
    $rolling = [];
    $nowHour = (int)$nowHour;

    for ($dayOffset = 0; $dayOffset < count($forecast) && count($rolling) < $count; $dayOffset++) {
        $date = $today->modify('+' . $dayOffset . ' days');
        $from = $dayOffset === 0 ? $nowHour : 0;
        $hours = pw_weather_day_hours($profile, $worldSlug, $date, $dayOffset, $forecast[$dayOffset], $revision, $nowHour, $from);
        foreach ($hours as $entry) {
            if (count($rolling) >= $count) {
                break;
            }
            $entry['date'] = $date->format('Y-m-d');
            $entry['is_now'] = ($dayOffset === 0 && $entry['hour'] === $nowHour);
            $rolling[] = $entry;
        }
    }

    return $rolling;
}

/**
 * @param bool $withHours  Include each day's hourly breakdown. Off by default so
 *                         the twelve-world glance strip, which only ever shows
 *                         current conditions, does not carry ~110 unused rows
 *                         per world.
 */
function pw_build_weather_forecast($profile, $worldSlug, $today = null, $withHours = false) {
    $timezone = new DateTimeZone('UTC');
    $nowHour = (int)(new DateTimeImmutable('now', $timezone))->format('G');
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

    if ($withHours) {
        foreach ($forecast as $offset => $day) {
            $date = $today->modify('+' . $offset . ' days');
            // Day 0 drops the hours that have already elapsed, so "Today" shrinks
            // as the day goes on and never repeats hours the Tomorrow card owns.
            $from = $offset === 0 ? $nowHour : 0;
            $forecast[$offset]['hours'] = pw_weather_day_hours($profile, $worldSlug, $date, $offset, $day, $revision, $nowHour, $from);
        }
    }

    return [
        'generated_for' => $dateKey,
        'timezone' => 'UTC',
        'now_hour' => $nowHour,
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
