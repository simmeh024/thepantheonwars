<?php
/**
 * Shared validation for Known Figures Control's create/update endpoints.
 * Slug is handled separately by each endpoint (required + unique on create,
 * immutable on update), same convention as Overlord/World/Forum Control.
 */

function pw_known_figure_motifs() {
    return ['pulse', 'glitch', 'twirl', 'glint', 'none'];
}

function pw_validate_known_figure_input($input) {
    $out = [];

    $out['name'] = isset($input['name']) ? trim((string)$input['name']) : '';
    if ($out['name'] === '' || mb_strlen($out['name']) > 100) {
        pw_error('Name is required and must be 100 characters or fewer.');
    }

    $out['eyebrow'] = isset($input['eyebrow']) ? trim((string)$input['eyebrow']) : '';
    if (mb_strlen($out['eyebrow']) > 150) {
        pw_error('Eyebrow line must be 150 characters or fewer.');
    }

    $out['status_line'] = isset($input['status_line']) ? trim((string)$input['status_line']) : '';
    if (mb_strlen($out['status_line']) > 200) {
        pw_error('Status line must be 200 characters or fewer.');
    }

    $out['portrait_image_url'] = isset($input['portrait_image_url']) ? trim((string)$input['portrait_image_url']) : '';
    if (mb_strlen($out['portrait_image_url']) > 255) {
        pw_error('Portrait image URL must be 255 characters or fewer.');
    }

    foreach (['body_paragraph_1', 'body_paragraph_2'] as $field) {
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

    $out['accent_color'] = isset($input['accent_color']) ? trim((string)$input['accent_color']) : '';
    if ($out['accent_color'] === '' || !preg_match('/^#[0-9a-fA-F]{3,8}$/', $out['accent_color'])) {
        pw_error('Accent color must be a hex color like #9a60ee.');
    }

    $out['motif'] = isset($input['motif']) ? (string)$input['motif'] : 'none';
    if (!in_array($out['motif'], pw_known_figure_motifs(), true)) {
        pw_error('Motif must be one of: ' . implode(', ', pw_known_figure_motifs()) . '.');
    }

    $out['signature_label'] = isset($input['signature_label']) ? trim((string)$input['signature_label']) : '';
    if (mb_strlen($out['signature_label']) > 150) {
        pw_error('Signature label must be 150 characters or fewer.');
    }

    $out['is_published'] = !empty($input['is_published']) ? 1 : 0;

    return $out;
}

function pw_validate_known_figure_slug($input) {
    $slug = isset($input['slug']) ? trim((string)$input['slug']) : '';
    if ($slug === '' || !preg_match('/^[a-z0-9\-]{1,100}$/', $slug)) {
        pw_error('Slug is required and may only contain lowercase letters, numbers, and hyphens.');
    }
    return $slug;
}
