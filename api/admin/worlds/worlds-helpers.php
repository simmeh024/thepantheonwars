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

    $out['overlord_name'] = isset($input['overlord_name']) ? trim((string)$input['overlord_name']) : '';
    $out['overlord_title'] = isset($input['overlord_title']) ? trim((string)$input['overlord_title']) : '';
    $out['overlord_page_slug'] = isset($input['overlord_page_slug']) ? trim((string)$input['overlord_page_slug'], "/ \t\n\r\0\x0B") : '';
    // Accept a full "overlord-name.html" value or just the bare slug -- strip
    // a trailing .html so the front end can always just do slug + '.html'.
    $out['overlord_page_slug'] = preg_replace('/\.html$/', '', $out['overlord_page_slug']);
    foreach (['overlord_name', 'overlord_title', 'overlord_page_slug'] as $field) {
        if (mb_strlen($out[$field]) > 100) {
            pw_error(ucfirst(str_replace('_', ' ', $field)) . ' must be 100 characters or fewer.');
        }
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

    return $out;
}

function pw_validate_world_slug($input) {
    $slug = isset($input['slug']) ? trim((string)$input['slug']) : '';
    if ($slug === '' || !preg_match('/^[a-z0-9\-]{1,50}$/', $slug)) {
        pw_error('Slug is required and may only contain lowercase letters, numbers, and hyphens.');
    }
    return $slug;
}
