<?php
/**
 * Shared validation for World Control's landmark create/update endpoints.
 * A landmark's kind is derived from whether it's attached to a layer
 * (restricted) or directly to the world (distant) -- not a client-editable
 * field, since it's implicit in which list the admin opened the modal from.
 */

function pw_validate_landmark_input($input) {
    $out = [];

    $out['name'] = isset($input['name']) ? trim((string)$input['name']) : '';
    if ($out['name'] === '' || mb_strlen($out['name']) > 100) {
        pw_error('Landmark name is required and must be 100 characters or fewer.');
    }

    $out['tag_label'] = isset($input['tag_label']) ? trim((string)$input['tag_label']) : '';
    if (mb_strlen($out['tag_label']) > 150) {
        pw_error('Tag label must be 150 characters or fewer.');
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

    return $out;
}
