<?php
/**
 * Shared logic for turning a raw git commit (subject/body) into a Development
 * Dispatch row: which of the 9 categories it belongs to, and a cleaned-up
 * subject line with any Conventional Commits prefix stripped.
 *
 * Used by both api/github-webhook.php (auto-inserts new dispatches on every
 * push) and api/admin/dispatches/resync.php (backfills any commits the
 * webhook missed by pulling the full history straight from the GitHub API).
 * Keeping this in one place means a new commit lands in the same category
 * whether it arrived via webhook or via a manual re-sync.
 */

function pw_dispatch_tag($subject, $body = '') {
    $subject = trim($subject);
    $text = strtolower($subject . ' ' . $body);
    $subjLow = strtolower($subject);

    $has = function (...$words) use ($text) {
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) {
                return true;
            }
        }
        return false;
    };

    if (strpos($subjLow, 'fix') === 0 || preg_match('/^(feat|chore|docs|refactor|style|test)(\(.+\))?:\s*fix/', $subjLow)) {
        return 'fix';
    }
    if ($has('experimental', 'beta test', 'early access', 'prototype', 'proof of concept')) {
        return 'experimental';
    }
    if ($has('webhook', 'cpanel', 'ftp auto-deploy', 'ftp actions', 'database', 'schema', 'migration',
             'github actions', 'workflow', 'git version control', '.htaccess', 'auto-deploy',
             'deploy workflow', 'switch to cpanel', 'member system', 'session', 'csrf', 'initial commit')) {
        return 'infrastructure';
    }
    if ($has('performance', 'faster', 'optimi', 'lighthouse', 'preload', 'defer', 'lazy', 'right-size',
             'stale css', 'stale browser', 'bust stale', 'prevent stale')) {
        return 'performance';
    }
    if ($has('refactor', 'reorganize', 'clean up', 'cleanup')) {
        return 'refactor';
    }
    if ($has('lore', 'world', 'overlord', 'chapter', ' book', 'character', 'district', ' map',
             'nexus veil', 'asmecu', 'neoh', 'high hammer', 'reanium', 'maerion', 'malric', 'korrus',
             'zura', 'syn dravus', 'lysara', 'bh-4', 'kael', 'babki', 'sed ', 'geof', 'beoctica',
             'terek', 'valerium', 'vermillia', 'quiz outcome', 'writing progress')) {
        return 'lore';
    }
    if ($has('styling', 'css', 'color-code', 'redesign', 'scrollbar', 'favicon', 'lightbox', 'crop',
             'framing', 'blurry', 'drop-cap', 'watermark', 'responsive', 'zebra', 'accent', 'emphasis',
             'tooltip', 'stand out', 'hover brighten')) {
        return 'ui_ux';
    }
    if ($has('improve', 'enhance', 'add match %', 'add percentages', 'add 3 boards',
             'add popular topics', 'add formatting toolbar', 'increase', 'bump hover')) {
        return 'improvement';
    }
    return 'feature';
}

function pw_dispatch_clean_subject($subject) {
    if (preg_match('/^(feat|fix|chore|docs|refactor|style|test)(\(.+\))?:\s*(.*)$/i', $subject, $m)) {
        return $m[3];
    }
    return $subject;
}

/** The 9 categories a dispatch can be filed under -- shared by admin edit UI. */
function pw_dispatch_valid_tags() {
    return ['feature', 'improvement', 'fix', 'performance', 'ui_ux', 'lore', 'infrastructure', 'refactor', 'experimental'];
}
