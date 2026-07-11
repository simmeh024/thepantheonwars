<?php
/**
 * Shared validation for World Control's layer create/update endpoints, plus
 * the sublocation-textarea parsing both endpoints need (one label per line,
 * blank lines dropped -- same "plain text parsed into rows" approach used
 * elsewhere in this codebase for books' preview_body paragraphs).
 */

$PW_WORLD_LAYER_TINT_KEYS = ['gold', 'purple', 'teal', 'green', 'orange', 'red', 'slate', 'black'];

function pw_validate_layer_input($input) {
    global $PW_WORLD_LAYER_TINT_KEYS;
    $out = [];

    $out['name'] = isset($input['name']) ? trim((string)$input['name']) : '';
    if ($out['name'] === '' || mb_strlen($out['name']) > 100) {
        pw_error('Layer name is required and must be 100 characters or fewer.');
    }

    $out['theme_tags'] = isset($input['theme_tags']) ? trim((string)$input['theme_tags']) : '';
    if (mb_strlen($out['theme_tags']) > 200) {
        pw_error('Theme tags must be 200 characters or fewer.');
    }

    $out['tagline'] = isset($input['tagline']) ? trim((string)$input['tagline']) : '';
    if (mb_strlen($out['tagline']) > 150) {
        pw_error('Tagline must be 150 characters or fewer.');
    }

    $out['description'] = isset($input['description']) ? trim((string)$input['description']) : '';
    if ($out['description'] === '') {
        pw_error('Description is required.');
    }

    $out['quote_text'] = isset($input['quote_text']) ? trim((string)$input['quote_text']) : '';
    if (mb_strlen($out['quote_text']) > 400) {
        pw_error('Quote must be 400 characters or fewer.');
    }

    $out['quote_cite'] = isset($input['quote_cite']) ? trim((string)$input['quote_cite']) : '';
    if (mb_strlen($out['quote_cite']) > 150) {
        pw_error('Quote attribution must be 150 characters or fewer.');
    }

    $out['tint_key'] = isset($input['tint_key']) ? (string)$input['tint_key'] : 'gold';
    if (!in_array($out['tint_key'], $PW_WORLD_LAYER_TINT_KEYS, true)) {
        pw_error('Not a valid tint color.');
    }

    return $out;
}

function pw_parse_sublocations_textarea($raw) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
    $out = [];
    foreach ($lines as $line) {
        $label = trim($line);
        if ($label === '') {
            continue;
        }
        if (mb_strlen($label) > 100) {
            $label = mb_substr($label, 0, 100);
        }
        $out[] = $label;
    }
    return $out;
}
