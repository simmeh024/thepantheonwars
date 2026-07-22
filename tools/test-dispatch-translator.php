<?php
/**
 * Lightweight regression checks for the local Dispatch Draft Translator.
 * Run on the server with: php tools/test-dispatch-translator.php
 *
 * This has no database or network dependency. It protects the essential rule
 * that a source action never survives inside a reader-facing object phrase
 * after a title has been converted into safer public wording.
 */
require_once dirname(__DIR__) . '/api/dispatch-translation-drafts.php';

$cases = [
    [
        'subject' => 'Unlock Asmecu on the worlds page with a full district map',
        'body' => 'New Asmecu section has 7 clickable districts and key landmarks.',
        'tag' => 'refactor',
        'expected' => 'BH-4 has opened Asmecu for exploration on the Worlds page. Visitors can now follow a full district map through 7 clickable districts and key landmarks.',
    ],
    [
        'subject' => 'Add one click copy button for raw commit message',
        'body' => '',
        'tag' => 'feature',
        'forbidden' => ['adds add one click'],
    ],
    [
        'subject' => 'Fix chapter one.html hero staying on Book One when preview unavailable',
        'body' => '',
        'tag' => 'fix',
        'forbidden' => ['fixes fix chapter', 'around fix chapter'],
    ],
    [
        'subject' => 'Preserve action intent across all Dispatch title rewrites',
        'body' => 'Safe replacements now retain the original action when a technical title becomes a concise reader-facing phrase.',
        'tag' => 'refactor',
        'contains' => ['development updates', 'original purpose'],
        'evidence' => ['reader safe dictionary'],
        'forbidden' => ['record around action intent', 'made action intent'],
    ],
    [
        'subject' => 'Add a reader-safe development summary',
        'body' => '',
        'tag' => 'feature',
        'options' => ['diff_context' => ['files_changed' => 4, 'areas' => ['site services', 'local site tooling']]],
        'contains' => ["\n\nTotal files edited: 4 in site services and local site tooling."],
    ],
    [
        'subject' => 'Metric cards: clickable modal with Latest dispatches, Trend vs previous period, BH-4 verified badge',
        'body' => '',
        'tag' => 'feature',
        'contains' => ['detailed view of current metrics and recent Dispatches'],
        'evidence' => ['reader safe dictionary'],
        'level' => 'high',
    ],
    [
        'subject' => 'Polish the signed in profil navigation',
        'body' => '',
        'tag' => 'ui_ux',
        'options' => ['spacy_analysis' => ['fuzzy_concept' => ['id' => 'profile_navigation', 'score' => 96]]],
        'contains' => ['profile menu for signed-in members'],
        'evidence' => ['reviewed fuzzy concept match'],
        'requires_editor_review' => true,
    ],
    // A strong sentence-embedding match (options['embedding_match'], as
    // pw_dispatch_draft_options_for_dispatch() would supply from a real
    // encode+lookup) contributes the same 5-point semantic_context evidence
    // spaCy's static-vector domain classifier already earns -- this is a
    // pure-PHP synthetic value, so no live embedding service is needed to
    // cover this behavior. The subject is deliberately nonsense so no other
    // dictionary/domain/action rule can fire and blur the signal being tested.
    [
        'subject' => 'Zqlm plexor for the tazwick subsystem',
        'body' => '',
        'tag' => 'improvement',
        'options' => ['embedding_match' => ['dispatch_id' => 123, 'score' => 0.83, 'subject' => 'Earlier plexor tazwick change', 'translation' => 'BH-4 already explained a similar earlier update.']],
        'evidence' => ['semantic context'],
        'best_semantic_match' => ['dispatch_id' => 123, 'score' => 0.83, 'subject' => 'Earlier plexor tazwick change', 'translation' => 'BH-4 already explained a similar earlier update.'],
    ],
    // Below the 0.75 threshold, pw_dispatch_nearest_embedding_match() itself
    // would never have returned a match at all -- but even if some future
    // caller passed a low score through anyway, it must not earn evidence.
    [
        'subject' => 'Zqlm plexor for the tazwick subsystem',
        'body' => '',
        'tag' => 'improvement',
        'options' => ['embedding_match' => ['dispatch_id' => 123, 'score' => 0.4, 'subject' => 'Unrelated', 'translation' => 'Unrelated.']],
        'forbidden_evidence' => ['semantic context'],
    ],
    // Developer-slang glossary: general jargon terms, not this project's own
    // historical commit titles. Confirms the raw jargon word never leaks
    // into reader-facing prose and that a match earns the same
    // reader_safe_dictionary evidence as any other dictionary entry.
    [
        'subject' => 'Hotfix a flaky login test',
        'body' => '',
        'tag' => 'fix',
        'contains' => ['urgent fix', 'inconsistent'],
        'forbidden' => ['hotfix', 'flaky'],
        'evidence' => ['reader safe dictionary'],
    ],
    [
        'subject' => 'Clean up tech debt and boilerplate in the login flow',
        'body' => '',
        'tag' => 'refactor',
        'contains' => ['accumulated maintenance work', 'repetitive setup code'],
        'forbidden' => ['tech debt', 'boilerplate'],
        'evidence' => ['reader safe dictionary'],
    ],
    // Interface-surface vocabulary. "modal" appeared in 13 commit subjects
    // with no dictionary entry at all, so it reached readers verbatim through
    // the reader-facing object phrase.
    [
        'subject' => 'Fix Issue Warning modal stacking over the open Member modal',
        'body' => '',
        'tag' => 'fix',
        'contains' => ['pop-up panel'],
        'forbidden' => ['modal'],
        'evidence' => ['reader safe dictionary'],
    ],
    [
        'subject' => 'Fix disabled OAuth provider button not actually hiding',
        'body' => '',
        'tag' => 'fix',
        'contains' => ['third-party sign-in'],
        'forbidden' => ['OAuth'],
        'evidence' => ['reader safe dictionary'],
    ],
    // The dictionary is ONE formatter rule regardless of how many terms it
    // rewrites. This subject matches three separate entries (modal, dropdown,
    // tooltip) and has no verb, no scope prefix, no body, no diff context and
    // no semantic support -- so its only evidence is recognized subject (25)
    // plus reader-safe dictionary (10) = 35%, which is medium. If each swap
    // incremented $rulesMatched separately it would reach the >= 2 rule gate,
    // be forced to 65%, and auto-publish without review on vocabulary alone.
    [
        'subject' => 'Tooltip and dropdown and modal',
        'body' => '',
        'tag' => 'ui_ux',
        'contains' => ['hover label', 'expanding menu', 'pop-up panel'],
        'forbidden' => ['tooltip', 'dropdown', 'modal'],
        'evidence' => ['reader safe dictionary'],
        'level' => 'medium',
    ],
    // Domain selection must weigh a deliberate subject mention above an
    // incidental one in the body. This is the real commit that published with
    // the wrong voice: its subject says "Dispatch" and "translation" outright,
    // but its body listed CSRF among the newly added dictionary entries, and
    // the old first-match cascade returned 'security' because that domain
    // simply sat earliest in the array. Both security benefit variants are
    // forbidden so the case does not depend on which one $pickVariant picks.
    [
        'subject' => 'Expand the Dispatch translation dictionary',
        'body' => 'Added sign-in and safeguard acronyms (OAuth, CSP, CSRF, 2FA, TOTP) to the reader-safe dictionary.',
        'tag' => 'improvement',
        'plan_domain' => 'content',
        'forbidden' => ['account or data path', 'layer of protection'],
    ],
    // The counterpart guard: a genuine security subject must still resolve to
    // the security voice, so the fix above cannot be satisfied by simply
    // demoting that domain.
    [
        'subject' => 'Add a dedicated rate limit for the login endpoint itself',
        'body' => '',
        'tag' => 'improvement',
        'plan_domain' => 'security',
    ],
    // A spaCy-extracted phrase may only be used when it comes from the
    // subject. spaCy analyses subject and body together, and its entity
    // labels (WORK_OF_ART, PRODUCT, ORG) readily match a quoted title sitting
    // inside a body. This is the real commit that published the object
    // "expand the Dispatch": its own body quoted the previous commit's title,
    // and because "Score" has no action template the draft fell through to the
    // spaCy path, which lifted that quoted phrase into public copy verbatim.
    // "score" is then stripped as a leading verb via spaCy's own VERB lemmas,
    // which the static verb list has never contained.
    [
        'subject' => 'Score Dispatch draft domains instead of first match',
        'body' => 'The published Dispatch for "Expand the Dispatch translation dictionary" rendered in the security voice.',
        'tag' => 'improvement',
        'options' => ['spacy_analysis' => [
            'entities' => ['Expand the Dispatch'],
            'actions' => ['score', 'render'],
        ]],
        'contains' => ['Dispatch draft domains instead of first match'],
        'forbidden' => ['expand the Dispatch', 'around score', 'of score'],
    ],
    // The counterpart guard: a spaCy phrase that IS grounded in the subject
    // must still be used, so the fix above cannot be satisfied by simply
    // ignoring spaCy's reader object altogether.
    [
        'subject' => 'Zqlm plexor for the tazwick subsystem',
        'body' => '',
        'tag' => 'improvement',
        'options' => ['spacy_analysis' => ['entities' => ['tazwick subsystem']]],
        'contains' => ['tazwick subsystem'],
    ],
    // An object phrase is dropped mid-sentence and so is normally lowercased,
    // but a bare lcfirst() published "BH-4" as "bH-4". Any acronym-led object
    // (BH-4, CSS, API, SQL, UTC) has the same problem.
    [
        'subject' => 'Improve BH-4 status imagery on the Admin Home page',
        'body' => '',
        'tag' => 'improvement',
        'forbidden' => ['bH-4'],
    ],
];

foreach ($cases as $case) {
    $result = pw_dispatch_end_user_draft($case['subject'], $case['body'], $case['tag'], $case['options'] ?? []);
    $draft = $result['draft'];
    if (isset($case['expected']) && $draft !== $case['expected']) {
        fwrite(STDERR, "Unexpected world-release draft:\n" . $draft . "\n");
        exit(1);
    }
    foreach ($case['forbidden'] ?? [] as $fragment) {
        if (stripos($draft, $fragment) !== false) {
            fwrite(STDERR, "Action leaked into reader-facing object: " . $draft . "\n");
            exit(1);
        }
    }
    foreach ($case['contains'] ?? [] as $fragment) {
        if (stripos($draft, $fragment) === false) {
            fwrite(STDERR, "Expected reader-facing context is missing: " . $draft . "\n");
            exit(1);
        }
    }
    foreach ($case['evidence'] ?? [] as $label) {
        if (!in_array($label, $result['confidence']['evidence'] ?? [], true)) {
            fwrite(STDERR, "Expected confidence evidence is missing: " . $label . "\n");
            exit(1);
        }
    }
    foreach ($case['forbidden_evidence'] ?? [] as $label) {
        if (in_array($label, $result['confidence']['evidence'] ?? [], true)) {
            fwrite(STDERR, "Confidence evidence should not include: " . $label . "\n");
            exit(1);
        }
    }
    if (isset($case['best_semantic_match']) && ($result['best_semantic_match'] ?? []) !== $case['best_semantic_match']) {
        fwrite(STDERR, "Unexpected best_semantic_match payload:\n" . print_r($result['best_semantic_match'] ?? null, true) . "\n");
        exit(1);
    }
    if (isset($case['plan_domain']) && ($result['plan']['domain'] ?? '') !== $case['plan_domain']) {
        fwrite(STDERR, "Unexpected plan domain: expected " . $case['plan_domain']
            . ", got " . ($result['plan']['domain'] ?? 'missing') . " for: " . $case['subject'] . "\n");
        exit(1);
    }
    if (isset($case['level']) && ($result['confidence']['level'] ?? '') !== $case['level']) {
        fwrite(STDERR, "Unexpected confidence level: " . ($result['confidence']['level'] ?? 'missing') . "\n");
        exit(1);
    }
    if (isset($case['requires_editor_review'])
        && (bool)($result['requires_editor_review'] ?? false) !== $case['requires_editor_review']) {
        fwrite(STDERR, "Unexpected fuzzy-match review requirement.\n");
        exit(1);
    }
}

echo "Dispatch translator regression checks passed.\n";
