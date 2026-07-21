<?php
/**
 * Shared validation for Forum Categories' create/update endpoints.
 */

function pw_validate_forum_category_input($input) {
    $name = isset($input['name']) ? trim($input['name']) : '';
    if ($name === '') {
        pw_error('Give the category a name.');
    }
    if (mb_strlen($name) > 100) {
        pw_error('That name is too long (100 characters max).');
    }
    return ['name' => $name];
}
