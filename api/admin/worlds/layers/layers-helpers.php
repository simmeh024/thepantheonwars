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

/**
 * The five condition keys a district quote can vary by. Fixed vocabulary,
 * matching pw_weather_icon_key(), so every world's editor is identical and a
 * quote cannot be orphaned by an admin editing a condition pool's wording.
 */
function pw_world_quote_condition_keys() {
    return ['clear', 'overcast', 'smog', 'acid-rain', 'storm'];
}

/**
 * Validates the optional per-condition quote variants on a layer payload.
 * Every one is optional: a condition with no variant falls back to the layer's
 * own quote, so these can be written one at a time.
 */
function pw_validate_layer_quote_variants($input) {
    $raw = isset($input['quote_variants']) && is_array($input['quote_variants']) ? $input['quote_variants'] : [];
    $out = [];
    foreach (pw_world_quote_condition_keys() as $key) {
        $entry = isset($raw[$key]) && is_array($raw[$key]) ? $raw[$key] : [];
        $text = isset($entry['quote_text']) ? trim((string)$entry['quote_text']) : '';
        $cite = isset($entry['quote_cite']) ? trim((string)$entry['quote_cite']) : '';
        if ($text === '') {
            // A blank quote clears that condition rather than storing an empty
            // row, so removing a variant is just clearing the field.
            continue;
        }
        if (mb_strlen($text) > 400) {
            pw_error('Each weather quote must be 400 characters or fewer.');
        }
        if (mb_strlen($cite) > 150) {
            pw_error('Each weather quote attribution must be 150 characters or fewer.');
        }
        $out[$key] = ['quote_text' => $text, 'quote_cite' => $cite];
    }
    return $out;
}

/**
 * Replaces a layer's stored variants with the submitted set.
 *
 * Fails soft: sql/migration_world_quote_variants.sql may not have been run, and
 * a missing table must not block saving the district itself.
 */
function pw_save_layer_quote_variants(PDO $db, $layerId, array $variants) {
    try {
        $db->prepare('DELETE FROM world_quote_variants WHERE entity_type = \'layer\' AND entity_id = ?')
           ->execute([(int)$layerId]);
        if (!$variants) {
            return;
        }
        $insert = $db->prepare(
            'INSERT INTO world_quote_variants (entity_type, entity_id, condition_key, quote_text, quote_cite)
             VALUES (\'layer\', ?, ?, ?, ?)'
        );
        foreach ($variants as $key => $variant) {
            $insert->execute([(int)$layerId, $key, $variant['quote_text'], $variant['quote_cite']]);
        }
    } catch (PDOException $e) {
        // Migration pending; the district saved normally and the variants can
        // be entered again once the table exists.
    }
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
