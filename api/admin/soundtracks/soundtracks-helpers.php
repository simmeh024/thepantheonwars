<?php
/**
 * Shared validation for Soundtrack Control's create/update endpoints. The
 * admin pastes a normal open.spotify.com share link; this parses it once
 * into (type, id) so both endpoints and every reader build the embed iframe
 * from a fixed template instead of re-parsing the URL in multiple places.
 */

function pw_soundtrack_embed_types() {
    return ['album', 'playlist', 'track'];
}

function pw_parse_spotify_url($url) {
    $pattern = '~open\.spotify\.com/(?:intl-[a-z]{2}/)?(album|playlist|track)/([A-Za-z0-9]+)~i';
    if (!preg_match($pattern, $url, $m)) {
        return null;
    }
    return ['type' => strtolower($m[1]), 'id' => $m[2]];
}

function pw_validate_soundtrack_input($input) {
    $out = [];

    $out['eyebrow'] = isset($input['eyebrow']) ? trim((string)$input['eyebrow']) : '';
    if (mb_strlen($out['eyebrow']) > 150) {
        pw_error('Eyebrow line must be 150 characters or fewer.');
    }

    $out['heading'] = isset($input['heading']) ? trim((string)$input['heading']) : '';
    if ($out['heading'] === '' || mb_strlen($out['heading']) > 200) {
        pw_error('Heading is required and must be 200 characters or fewer.');
    }

    $out['description'] = isset($input['description']) ? trim((string)$input['description']) : '';
    $out['description'] = $out['description'] === '' ? null : $out['description'];

    $spotifyUrl = isset($input['spotify_url']) ? trim((string)$input['spotify_url']) : '';
    if ($spotifyUrl === '' || mb_strlen($spotifyUrl) > 500) {
        pw_error('Spotify link is required and must be 500 characters or fewer.');
    }
    $parsed = pw_parse_spotify_url($spotifyUrl);
    if ($parsed === null) {
        pw_error('Could not recognize that as an open.spotify.com album, playlist, or track link.');
    }
    $out['spotify_url'] = $spotifyUrl;
    $out['spotify_embed_type'] = $parsed['type'];
    $out['spotify_embed_id'] = $parsed['id'];

    $out['is_published'] = !empty($input['is_published']) ? 1 : 0;

    return $out;
}
