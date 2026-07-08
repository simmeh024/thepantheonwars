<?php
/**
 * Shared validation for Book Control's create/update endpoints. Both take
 * the same field set (a book, with or without an existing id), so the
 * validation logic is centralized here rather than duplicated.
 */

function pw_validate_book_input($input) {
    $out = [];

    $out['book_number'] = isset($input['book_number']) ? (int)$input['book_number'] : 0;
    if ($out['book_number'] <= 0) {
        pw_error('Book number must be a positive number.');
    }

    $out['saga_phase'] = isset($input['saga_phase']) ? (int)$input['saga_phase'] : 0;
    if (!in_array($out['saga_phase'], [1, 2, 3], true)) {
        pw_error('Saga phase must be I, II, or III.');
    }

    $out['writing_stage'] = isset($input['writing_stage']) ? (int)$input['writing_stage'] : 0;
    if ($out['writing_stage'] < 1 || $out['writing_stage'] > 15) {
        pw_error('Writing stage must be between 1 and 15.');
    }

    $out['title'] = isset($input['title']) ? trim((string)$input['title']) : '';
    if ($out['title'] === '' || mb_strlen($out['title']) > 255) {
        pw_error('Title is required and must be 255 characters or fewer.');
    }

    $out['status_label'] = isset($input['status_label']) ? trim((string)$input['status_label']) : '';
    if ($out['status_label'] === '' || mb_strlen($out['status_label']) > 100) {
        pw_error('Status label is required and must be 100 characters or fewer.');
    }

    $out['meta_text'] = isset($input['meta_text']) ? trim((string)$input['meta_text']) : '';
    if (mb_strlen($out['meta_text']) > 500) {
        pw_error('Meta text must be 500 characters or fewer.');
    }
    if ($out['meta_text'] === '') {
        $out['meta_text'] = null;
    }

    $out['description'] = isset($input['description']) ? trim((string)$input['description']) : '';

    $urlFields = [
        'cover_image_url', 'character_image_url', 'preview_hero_image_url',
        'buy_kobo_url', 'buy_amazon_url', 'buy_apple_url', 'buy_bn_url',
    ];
    foreach ($urlFields as $field) {
        $value = isset($input[$field]) ? trim((string)$input[$field]) : '';
        if ($value !== '' && mb_strlen($value) > 500) {
            pw_error(ucfirst(str_replace('_', ' ', $field)) . ' must be 500 characters or fewer.');
        }
        $out[$field] = $value === '' ? null : $value;
    }

    $out['character_alt'] = isset($input['character_alt']) ? trim((string)$input['character_alt']) : '';
    $out['character_alt'] = $out['character_alt'] === '' ? null : $out['character_alt'];

    $out['preview_enabled'] = !empty($input['preview_enabled']) ? 1 : 0;

    $out['preview_eyebrow'] = isset($input['preview_eyebrow']) ? trim((string)$input['preview_eyebrow']) : '';
    $out['preview_eyebrow'] = $out['preview_eyebrow'] === '' ? null : $out['preview_eyebrow'];

    $out['preview_lede'] = isset($input['preview_lede']) ? trim((string)$input['preview_lede']) : '';
    $out['preview_lede'] = $out['preview_lede'] === '' ? null : $out['preview_lede'];

    $out['preview_body'] = isset($input['preview_body']) ? trim((string)$input['preview_body']) : '';
    $out['preview_body'] = $out['preview_body'] === '' ? null : $out['preview_body'];

    $out['preview_quote'] = isset($input['preview_quote']) ? trim((string)$input['preview_quote']) : '';
    $out['preview_quote'] = $out['preview_quote'] === '' ? null : $out['preview_quote'];

    $out['preview_quote_cite'] = isset($input['preview_quote_cite']) ? trim((string)$input['preview_quote_cite']) : '';
    if (mb_strlen($out['preview_quote_cite']) > 255) {
        pw_error('Quote attribution must be 255 characters or fewer.');
    }
    $out['preview_quote_cite'] = $out['preview_quote_cite'] === '' ? null : $out['preview_quote_cite'];

    if ($out['preview_enabled'] && $out['preview_body'] === null) {
        pw_error('Write a preview chapter before enabling the preview.');
    }

    return $out;
}
