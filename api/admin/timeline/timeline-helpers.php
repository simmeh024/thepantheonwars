<?php
/**
 * Shared validation for Timeline Control's create/update endpoints.
 * Slug is handled separately by each endpoint (required + unique on create,
 * immutable on update), same convention as Known Figures/Overlord Control.
 */

function pw_validate_timeline_input($input) {
    $out = [];

    $out['title'] = isset($input['title']) ? trim((string)$input['title']) : '';
    if ($out['title'] === '' || mb_strlen($out['title']) > 150) {
        pw_error('Title is required and must be 150 characters or fewer.');
    }

    $out['era_label'] = isset($input['era_label']) ? trim((string)$input['era_label']) : '';
    if (mb_strlen($out['era_label']) > 100) {
        pw_error('Era label must be 100 characters or fewer.');
    }

    // Free text on purpose: in-world time has no real calendar to validate
    // against, so anything from "Cycle 4.207" to "Before the Sundering" is fine.
    $out['date_label'] = isset($input['date_label']) ? trim((string)$input['date_label']) : '';
    if (mb_strlen($out['date_label']) > 100) {
        pw_error('Date label must be 100 characters or fewer.');
    }

    $out['summary'] = isset($input['summary']) ? trim((string)$input['summary']) : '';
    if (mb_strlen($out['summary']) > 400) {
        pw_error('Summary must be 400 characters or fewer.');
    }

    $out['body'] = isset($input['body']) ? trim((string)$input['body']) : '';
    $out['body'] = $out['body'] === '' ? null : $out['body'];

    $out['image_url'] = isset($input['image_url']) ? trim((string)$input['image_url']) : '';
    if (mb_strlen($out['image_url']) > 255) {
        pw_error('Image URL must be 255 characters or fewer.');
    }

    $out['accent_color'] = isset($input['accent_color']) ? trim((string)$input['accent_color']) : '';
    if ($out['accent_color'] === '' || !preg_match('/^#[0-9a-fA-F]{3,8}$/', $out['accent_color'])) {
        pw_error('Accent color must be a hex color like #a279ec.');
    }

    // NULL / 0 / '' all mean "always visible". Anything else must be a real
    // reputation level -- validated against the table by the caller, because a
    // stale id here would silently make an event permanently unreachable.
    $rawLevel = array_key_exists('required_level_id', $input) ? $input['required_level_id'] : null;
    $out['required_level_id'] = ($rawLevel === null || $rawLevel === '' || (int)$rawLevel <= 0)
        ? null
        : (int)$rawLevel;

    $out['is_published'] = !empty($input['is_published']) ? 1 : 0;

    return $out;
}

/**
 * A required level must exist. Without this an admin typo (or a level deleted
 * in another tab) would seal an event behind a gate nobody can ever satisfy,
 * and the public endpoint would treat the missing join row as "open" -- so the
 * failure would be silent in both directions.
 */
function pw_validate_timeline_level($db, $levelId) {
    if ($levelId === null) {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM reputation_levels WHERE id = ?');
    $stmt->execute([$levelId]);
    if (!$stmt->fetch()) {
        pw_error('That reputation level no longer exists.', 409);
    }
    return $levelId;
}

function pw_validate_timeline_slug($input) {
    $slug = isset($input['slug']) ? trim((string)$input['slug']) : '';
    if ($slug === '' || !preg_match('/^[a-z0-9\-]{1,100}$/', $slug)) {
        pw_error('Slug is required and may only contain lowercase letters, numbers, and hyphens.');
    }
    return $slug;
}
