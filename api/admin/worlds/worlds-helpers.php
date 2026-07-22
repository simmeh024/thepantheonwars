<?php
/**
 * Shared validation for World Control's create/update endpoints. Slug is
 * handled separately by each endpoint (required + unique on create,
 * immutable on update -- topics/other data don't reference a world's slug
 * today, but keeping it stable avoids ever having to worry about it).
 */

function pw_validate_world_input($input) {
    $out = [];

    $out['name'] = isset($input['name']) ? trim((string)$input['name']) : '';
    if ($out['name'] === '' || mb_strlen($out['name']) > 100) {
        pw_error('Name is required and must be 100 characters or fewer.');
    }

    $out['tagline'] = isset($input['tagline']) ? trim((string)$input['tagline']) : '';
    if (mb_strlen($out['tagline']) > 200) {
        pw_error('Tagline must be 200 characters or fewer.');
    }

    $out['card_blurb'] = isset($input['card_blurb']) ? trim((string)$input['card_blurb']) : '';
    if (mb_strlen($out['card_blurb']) > 300) {
        pw_error('Card blurb must be 300 characters or fewer.');
    }

    $urlFields = ['thumb_image_url', 'portrait_image_url', 'map_thumb_image_url', 'map_full_image_url'];
    foreach ($urlFields as $field) {
        $value = isset($input[$field]) ? trim((string)$input[$field]) : '';
        if (mb_strlen($value) > 255) {
            pw_error(ucfirst(str_replace('_', ' ', $field)) . ' must be 255 characters or fewer.');
        }
        $out[$field] = $value;
    }

    $out['status'] = isset($input['status']) ? (string)$input['status'] : 'locked';
    if (!in_array($out['status'], ['available', 'locked'], true)) {
        pw_error('Status must be "available" or "locked".');
    }

    $out['lore_status_label'] = isset($input['lore_status_label']) ? trim((string)$input['lore_status_label']) : 'Lore Coming Soon';
    if ($out['lore_status_label'] === '') {
        $out['lore_status_label'] = 'Lore Coming Soon';
    }
    if (mb_strlen($out['lore_status_label']) > 100) {
        pw_error('Lore status label must be 100 characters or fewer.');
    }

    $out['intro_paragraph_1'] = isset($input['intro_paragraph_1']) ? trim((string)$input['intro_paragraph_1']) : '';
    $out['intro_paragraph_1'] = $out['intro_paragraph_1'] === '' ? null : $out['intro_paragraph_1'];

    $out['intro_paragraph_2'] = isset($input['intro_paragraph_2']) ? trim((string)$input['intro_paragraph_2']) : '';
    $out['intro_paragraph_2'] = $out['intro_paragraph_2'] === '' ? null : $out['intro_paragraph_2'];

    $out['layout_orientation'] = isset($input['layout_orientation']) ? (string)$input['layout_orientation'] : 'horizontal';
    if (!in_array($out['layout_orientation'], ['vertical', 'horizontal'], true)) {
        pw_error('Layout orientation must be "vertical" or "horizontal".');
    }

    $out['altitude_top_label'] = isset($input['altitude_top_label']) ? trim((string)$input['altitude_top_label']) : '';
    $out['altitude_bottom_label'] = isset($input['altitude_bottom_label']) ? trim((string)$input['altitude_bottom_label']) : '';
    foreach (['altitude_top_label', 'altitude_bottom_label'] as $field) {
        if (mb_strlen($out[$field]) > 100) {
            pw_error(ucfirst(str_replace('_', ' ', $field)) . ' must be 100 characters or fewer.');
        }
    }

    $out['map_caption'] = isset($input['map_caption']) ? trim((string)$input['map_caption']) : '';
    if (mb_strlen($out['map_caption']) > 255) {
        pw_error('Map caption must be 255 characters or fewer.');
    }

    // This world's signal colour, stored as bare "R, G, B" components rather
    // than a CSS colour so one value drives both a solid fill and a
    // translucent glow (the header weather widget uses it both ways). Validated
    // strictly because it is interpolated straight into a CSS custom property.
    $accent = isset($input['accent_rgb']) ? trim((string)$input['accent_rgb']) : '';
    if ($accent !== '') {
        if (!preg_match('/^\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*$/', $accent, $m)) {
            pw_error('Accent colour must be three comma-separated numbers, for example "154, 96, 238".');
        }
        foreach ([1, 2, 3] as $i) {
            if ((int)$m[$i] > 255) {
                pw_error('Each accent colour component must be between 0 and 255.');
            }
        }
        $accent = (int)$m[1] . ', ' . (int)$m[2] . ', ' . (int)$m[3];
    }
    $out['accent_rgb'] = $accent;

    return $out;
}

/**
 * Whether worlds.accent_rgb exists yet (sql/migration_weather_widget.sql).
 *
 * A missing column is a hard SQL error rather than a NULL, so every World
 * Control read and write checks here first and falls back to its previous
 * column list -- a deploy landing before the migration cannot break the admin
 * console. Request-cached; this is a cheap metadata read but it is on every
 * World Control load. Same approach as pw_dispatch_has_visibility_column().
 */
function pw_worlds_has_accent_column() {
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $has = (bool)pw_db()->query("SHOW COLUMNS FROM worlds LIKE 'accent_rgb'")->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

function pw_validate_world_slug($input) {
    $slug = isset($input['slug']) ? trim((string)$input['slug']) : '';
    if ($slug === '' || !preg_match('/^[a-z0-9\-]{1,50}$/', $slug)) {
        pw_error('Slug is required and may only contain lowercase letters, numbers, and hyphens.');
    }
    return $slug;
}
