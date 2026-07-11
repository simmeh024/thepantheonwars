<?php
/**
 * Shared validation for Overlord Control's create/update endpoints. Slug is
 * handled separately by each endpoint (required + unique on create,
 * immutable on update), same convention as World/Forum Control.
 */

function pw_validate_overlord_input($input) {
    $out = [];

    $out['name'] = isset($input['name']) ? trim((string)$input['name']) : '';
    if ($out['name'] === '' || mb_strlen($out['name']) > 100) {
        pw_error('Name is required and must be 100 characters or fewer.');
    }

    $out['epithet'] = isset($input['epithet']) ? trim((string)$input['epithet']) : '';
    if (mb_strlen($out['epithet']) > 100) {
        pw_error('Epithet must be 100 characters or fewer.');
    }

    $out['world_id'] = isset($input['world_id']) ? (int)$input['world_id'] : 0;
    if ($out['world_id'] <= 0) {
        $out['world_id'] = null;
    }

    $out['pronoun_possessive'] = isset($input['pronoun_possessive']) ? (string)$input['pronoun_possessive'] : 'their';
    if (!in_array($out['pronoun_possessive'], ['his', 'her', 'their'], true)) {
        pw_error('Pronoun must be "his", "her", or "their".');
    }

    $out['status'] = isset($input['status']) ? (string)$input['status'] : 'locked';
    if (!in_array($out['status'], ['available', 'locked'], true)) {
        pw_error('Status must be "available" or "locked".');
    }

    $out['portrait_image_url'] = isset($input['portrait_image_url']) ? trim((string)$input['portrait_image_url']) : '';
    if (mb_strlen($out['portrait_image_url']) > 255) {
        pw_error('Portrait image URL must be 255 characters or fewer.');
    }

    $out['card_teaser'] = isset($input['card_teaser']) ? trim((string)$input['card_teaser']) : '';
    if (mb_strlen($out['card_teaser']) > 300) {
        pw_error('Card teaser must be 300 characters or fewer.');
    }

    foreach (['bio_paragraph_1', 'bio_paragraph_2', 'bio_paragraph_3'] as $field) {
        $out[$field] = isset($input[$field]) ? trim((string)$input[$field]) : '';
        $out[$field] = $out[$field] === '' ? null : $out[$field];
    }

    $out['quote_text'] = isset($input['quote_text']) ? trim((string)$input['quote_text']) : '';
    if (mb_strlen($out['quote_text']) > 400) {
        pw_error('Quote text must be 400 characters or fewer.');
    }

    $out['quote_cite'] = isset($input['quote_cite']) ? trim((string)$input['quote_cite']) : '';
    if (mb_strlen($out['quote_cite']) > 150) {
        pw_error('Quote attribution must be 150 characters or fewer.');
    }

    foreach (['accent_color', 'accent_glow'] as $field) {
        $out[$field] = isset($input[$field]) ? trim((string)$input[$field]) : '';
        if ($out[$field] !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $out[$field])) {
            pw_error(ucfirst(str_replace('_', ' ', $field)) . ' must be a hex color like #a279ec.');
        }
    }

    $out['meta_title'] = isset($input['meta_title']) ? trim((string)$input['meta_title']) : '';
    if (mb_strlen($out['meta_title']) > 150) {
        pw_error('Meta title must be 150 characters or fewer.');
    }

    $out['meta_description'] = isset($input['meta_description']) ? trim((string)$input['meta_description']) : '';
    if (mb_strlen($out['meta_description']) > 300) {
        pw_error('Meta description must be 300 characters or fewer.');
    }

    return $out;
}

function pw_validate_overlord_slug($input) {
    $slug = isset($input['slug']) ? trim((string)$input['slug']) : '';
    if ($slug === '' || !preg_match('/^[a-z0-9\-]{1,100}$/', $slug)) {
        pw_error('Slug is required and may only contain lowercase letters, numbers, and hyphens.');
    }
    return $slug;
}
