<?php
/**
 * Shared logic for turning a raw git commit (subject/body/diff-context) into
 * a Development Dispatch category: which of the 9 categories it belongs to,
 * how confident that guess is, and a cleaned-up subject line with any
 * Conventional Commits prefix stripped.
 *
 * Used by both api/github-webhook.php (auto-inserts new dispatches on every
 * push) and api/admin/dispatches/resync.php (backfills any commits the
 * webhook missed by pulling the full history straight from the GitHub API).
 * Keeping this in one place means a new commit lands in the same category
 * whether it arrived via webhook or via a manual re-sync.
 *
 * Categorization is a weighted-evidence scorer, not a first-match cascade:
 * every category accumulates points from four independent signals (a
 * Conventional Commits prefix, word-boundary keyword hits in the subject,
 * the same in the body, and the diff's file-scope areas/extensions), and
 * whichever category has the highest total wins. This replaced an if/elif
 * cascade that always favoured "infrastructure" over "performance"/"lore"/
 * etc. purely because that check happened to run first, and that matched
 * on bare substrings ("character" inside "3500 character limit" reading as
 * a story character). A margin-aware confidence score comes out of the same
 * tally, so a commit where two categories score close together is flagged
 * honestly as uncertain rather than silently picking the first alphabetical
 * (or first-checked) winner.
 */

/** The 9 categories a dispatch can be filed under -- shared by admin edit UI.
 *  Order doubles as the tie-break priority when two categories score equally. */
function pw_dispatch_valid_tags() {
    return ['fix', 'experimental', 'infrastructure', 'performance', 'refactor', 'lore', 'ui_ux', 'improvement', 'feature'];
}

function pw_dispatch_clean_subject($subject) {
    if (preg_match('/^(feat|fix|chore|docs|refactor|style|test|perf)(\(.+\))?:\s*(.*)$/i', $subject, $m)) {
        return $m[3];
    }
    return $subject;
}

// Every recognised keyword per category except 'fix' (handled entirely by
// the Conventional Commits prefix / bare-"fix"-prefix rule below) and
// 'feature' (the zero-evidence fallback every other category is scored
// against).
/**
 * Whether dispatch_entries carries the is_hidden column yet
 * (sql/migration_dispatch_visibility.sql). Checked rather than assumed so a
 * deploy that lands before the migration keeps serving Dispatches normally
 * instead of failing every public request with an unknown-column error. A
 * COALESCE cannot do this job: a missing column is a hard SQL error, not NULL.
 *
 * Cached for the request -- one SHOW COLUMNS per request at most, and only on
 * the paths that actually filter by visibility.
 */
function pw_dispatch_has_visibility_column($db) {
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $stmt = $db->query("SHOW COLUMNS FROM dispatch_entries LIKE 'is_hidden'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

function pw_dispatch_category_keywords() {
    return [
        'experimental' => ['experimental', 'beta test', 'early access', 'prototype', 'proof of concept'],
        'infrastructure' => ['webhook', 'cpanel', 'ftp auto-deploy', 'ftp actions', 'database', 'schema', 'migration',
            'github actions', 'workflow', 'git version control', '.htaccess', 'auto-deploy',
            'deploy workflow', 'switch to cpanel', 'member system', 'session', 'csrf', 'initial commit'],
        'performance' => ['performance', 'faster', 'optimi', 'lighthouse', 'preload', 'defer', 'lazy', 'right-size',
            'stale css', 'stale browser', 'bust stale', 'prevent stale', 'compress', 'gzip'],
        'refactor' => ['refactor', 'reorganize', 'clean up', 'cleanup'],
        'lore' => ['lore', 'world', 'overlord', 'chapter', 'book', 'character', 'district', 'map',
            'nexus veil', 'asmecu', 'neoh', 'high hammer', 'reanium', 'maerion', 'malric', 'korrus',
            'zura', 'syn dravus', 'lysara', 'bh-4', 'kael', 'babki', 'sed', 'geof', 'beoctica',
            'terek', 'valerium', 'vermillia', 'quiz outcome', 'writing progress'],
        'ui_ux' => ['styling', 'css', 'color-code', 'redesign', 'scrollbar', 'favicon', 'lightbox', 'crop',
            'framing', 'blurry', 'drop-cap', 'watermark', 'responsive', 'zebra', 'accent', 'emphasis',
            'tooltip', 'stand out', 'hover brighten'],
        'improvement' => ['improve', 'enhance', 'add match %', 'add percentages', 'add 3 boards',
            'add popular topics', 'add formatting toolbar', 'increase', 'bump hover'],
    ];
}

// Conventional Commits prefix -> the category that prefix is decent
// evidence for. 'feat' isn't included: it's used for everything from a new
// public feature to an internal tooling tweak, so it's too weak a signal to
// assert any one category over the others (leaving it out lets the keyword/
// diff-context signals decide instead of a shaky default).
function pw_dispatch_prefix_category_map() {
    return [
        'fix' => 'fix',
        'refactor' => 'refactor',
        'style' => 'ui_ux',
        'docs' => 'infrastructure',
        'chore' => 'infrastructure',
        'test' => 'infrastructure',
        'perf' => 'performance',
    ];
}

// Matches $keyword as a whole word/phrase wherever both of its ends land on
// an alphanumeric character, and as a plain substring otherwise (keywords
// like ".htaccess" or "add match %" start/end on punctuation, where a strict
// \b boundary is meaningless). This is what stops "character" from also
// matching inside "recharacterize", while still matching ".htaccess" or a
// percent-sign phrase correctly.
function pw_dispatch_keyword_matches($text, $keyword) {
    $pattern = preg_quote($keyword, '/');
    $startsAlnum = ctype_alnum($keyword[0]);
    $endsAlnum = ctype_alnum(substr($keyword, -1));
    $left = $startsAlnum ? '\b' : '';
    $right = $endsAlnum ? '\b' : '';
    return preg_match('/' . $left . $pattern . $right . '/i', $text) === 1;
}

// Maps the diff-context's allow-listed file-type/product-area labels
// (pw_dispatch_diff_context_from_paths()) onto a category, for the labels
// unambiguous enough to trust as ground truth. Broader labels ("site
// services", "the Admin Console") are deliberately left unmapped -- they're
// too generic to imply any single one of the 9 categories confidently.
function pw_dispatch_diffcontext_category_map() {
    return [
        'style files' => 'ui_ux',
        'the visual interface' => 'ui_ux',
        'database definitions' => 'infrastructure',
        'the site database' => 'infrastructure',
        'deployment configuration' => 'infrastructure',
        'project documentation' => 'infrastructure',
        'local site tooling' => 'infrastructure',
        'member sign-in services' => 'infrastructure',
        'member session services' => 'infrastructure',
        'worldbuilding pages' => 'lore',
        'book content' => 'lore',
    ];
}

/**
 * Scores every category against the given commit, returning
 * ['tag' => ..., 'confidence' => 0-100, 'scores' => [category => points]].
 * $diffContext is the same shape pw_dispatch_diff_context_from_paths()
 * returns (['areas' => [...], 'extensions' => [...]]); pass [] when it
 * isn't available (e.g. a resync commit past the bounded lookup budget).
 */
function pw_dispatch_categorize($subject, $body = '', array $diffContext = []) {
    $subject = trim($subject);
    $body = trim((string)$body);
    $scores = array_fill_keys(pw_dispatch_valid_tags(), 0);

    // Signal 1: Conventional Commits prefix (weight 65). A deliberate,
    // explicit type marker is the strongest single signal available -- on
    // its own it's enough to clear the 65% "high confidence" bar, the same
    // "one strong deterministic rule can set the floor" precedent already
    // established for translation confidence (docs/dispatch-spacy.md). A
    // bare "Fix ..." subject (no colon) or "<any-prefix>: fix ..." both
    // still count as a fix, matching this function's pre-scoring behaviour.
    $subjLow = strtolower($subject);
    if (strpos($subjLow, 'fix') === 0 || preg_match('/^(feat|chore|docs|refactor|style|test|perf)(\(.+\))?:\s*fix/', $subjLow)) {
        $scores['fix'] += 65;
    } elseif (preg_match('/^(feat|fix|chore|docs|refactor|style|test|perf)(\(.+\))?:/i', $subject, $m)) {
        $prefixMap = pw_dispatch_prefix_category_map();
        $prefix = strtolower($m[1]);
        if (isset($prefixMap[$prefix])) {
            $scores[$prefixMap[$prefix]] += 65;
        }
    }

    // Signals 2 & 3: word-boundary keyword hits, subject weighted well above
    // body (a subject-line mention is deliberate; a body mention is often
    // just supporting detail). Presence is boolean per category so a
    // category with a long keyword list (lore has ~30) doesn't automatically
    // outscore one with a short list (performance has ~13) merely by having
    // more chances to match. A subject hit alone (50) sits just under the
    // review floor -- one keyword mention is corroborating evidence, not
    // proof, and should combine with a second signal (body, diff-context, or
    // another subject keyword from a different pass) to clear it.
    foreach (pw_dispatch_category_keywords() as $category => $words) {
        foreach ($words as $word) {
            if (pw_dispatch_keyword_matches($subject, $word)) {
                $scores[$category] += 50;
                break;
            }
        }
        foreach ($words as $word) {
            if (pw_dispatch_keyword_matches($body, $word)) {
                $scores[$category] += 20;
                break;
            }
        }
    }

    // Signal 4: diff-context file scope (weight 45) -- ground truth about
    // what actually changed, immune to how the commit message is worded.
    if (!empty($diffContext)) {
        $diffMap = pw_dispatch_diffcontext_category_map();
        $labels = array_merge($diffContext['areas'] ?? [], $diffContext['extensions'] ?? []);
        $hit = [];
        foreach ($labels as $label) {
            if (isset($diffMap[$label])) {
                $hit[$diffMap[$label]] = true;
            }
        }
        foreach (array_keys($hit) as $category) {
            $scores[$category] += 45;
        }
    }

    // PHP's array sort functions have been stable since 8.0, so equal scores
    // keep pw_dispatch_valid_tags()'s declared order -- the same priority
    // the old if/elif cascade used, now only reached as a genuine tie-break
    // instead of unconditionally.
    arsort($scores);
    $tag = array_key_first($scores);
    $winnerScore = reset($scores);
    $runnerUpScore = count($scores) > 1 ? array_values($scores)[1] : 0;
    $margin = $winnerScore - $runnerUpScore;

    if ($winnerScore === 0) {
        // No signal fired at all -- 'feature' is a pure default guess, not a
        // detected category, so say so plainly rather than implying evidence
        // that doesn't exist.
        $tag = 'feature';
        $confidence = 20;
    } elseif ($margin < 15) {
        // Two or more categories scored close together: genuinely contested,
        // worth a human glance even though a winner had to be picked.
        $confidence = max(15, min(100, $winnerScore) - 25);
    } else {
        $confidence = min(100, $winnerScore);
    }

    return ['tag' => $tag, 'confidence' => $confidence, 'scores' => $scores];
}

// Backward-compatible wrapper for any caller that only wants the tag string.
function pw_dispatch_tag($subject, $body = '') {
    return pw_dispatch_categorize($subject, $body)['tag'];
}

// A dispatch is worth a human glance when it was auto-assigned and the
// scorer itself wasn't confident -- the same 65% "high confidence" floor
// already established for translation confidence tiers (see
// docs/dispatch-spacy.md), so the two systems read consistently to an admin.
function pw_dispatch_category_needs_review($confidence, $source) {
    return $source === 'auto' && (int)$confidence < 65;
}
