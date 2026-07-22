<?php
/**
 * Local, deterministic copy formatter for end-user Dispatch drafts. It does
 * not call an external service. High-confidence results can be published
 * automatically; medium and low results remain in dispatch_translation_drafts
 * for an editor to approve or edit.
 */
require_once __DIR__ . '/dispatch-diff-context.php';
require_once __DIR__ . '/dispatch-fuzzy-concepts.php';
require_once __DIR__ . '/dispatch-spacy.php';
require_once __DIR__ . '/dispatch-embeddings.php';

function pw_dispatch_draft_phrase_is_recent(string $candidate, array $recentTranslations): bool
{
    $needle = strtolower(trim(preg_replace('/[^a-z0-9 ]/i', '', $candidate)));
    if (strlen($needle) < 32) {
        return false;
    }
    foreach ($recentTranslations as $translation) {
        $haystack = strtolower(trim(preg_replace('/[^a-z0-9 ]/i', '', (string)$translation)));
        if ($haystack !== '' && strpos($haystack, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function pw_dispatch_draft_domain(string $subjectText, string $tag, array $diffContext, string $bodyText = ''): string
{
    $scopeText = implode(' ', $diffContext['areas'] ?? []) . ' ' . implode(' ', $diffContext['extensions'] ?? []);
    // A named world, a district map, or an explicit worldbuilding scope is a
    // decisive reader-facing cue. Do this before broad technical vocabulary:
    // a world record can legitimately mention image loading or responsive
    // behaviour without becoming a performance Dispatch. This one stays a hard
    // pre-check rather than a score, per the documented rule that named
    // worlds, maps, districts and books are decisive content signals.
    if (preg_match('/\b(?:Asmecu|Cerius|Neoh|High Hammer|worlds?|worldbuilding|district(?:s)?|map|overlord|lore|book|chapter)\b/i', $subjectText . ' ' . $bodyText . ' ' . $scopeText)) {
        return 'content';
    }
    $domains = [
        'security' => '/\b(?:security|csrf|password|login|session|privacy|gdpr|permission|authentication|authorization|header|x-frame|referrer)\b/i',
        'database' => '/\b(?:database|sql|mysql|mariadb|query|index|migration|rollup)\b/i',
        'community' => '/\b(?:forum|community|topic|reply|comment|member|moderator|notification|report|reaction|profile)\b/i',
        'interface' => '/\b(?:css|ui|ux|interface|layout|sidebar|card|modal|button|icon|image|hero|responsive|focus|animation)\b/i',
        'performance' => '/\b(?:performance|faster|speed|cache|loading|lcp|lighthouse|lazy|preload|defer|bandwidth|core web vitals)\b/i',
        'content' => '/\b(?:dispatch|translation|story|character|quiz)\b/i',
        'operations' => '/\b(?:admin|backup|system status|audit log|github|webhook|cron|deploy|monitor|analytics)\b/i',
    ];
    // Score every domain independently instead of returning the first pattern
    // that happens to match. A flat cascade made array position outrank
    // evidence: "security" sat first, so a single incidental body mention of a
    // security word beat an unambiguous subject line. That is exactly how the
    // commit "Expand the Dispatch translation dictionary" published with the
    // security voice ("The affected account or data path now carries a more
    // deliberate safeguard") -- its body listed CSRF among the new dictionary
    // entries, while its subject said "Dispatch" and "translation" outright.
    // This mirrors the identical fix already made to pw_dispatch_categorize()
    // in api/dispatch-helpers.php; the weights follow that function's
    // precedent, with the subject weighted well above the body because a
    // subject-line mention is deliberate and a body mention is often just
    // supporting detail. Presence is boolean per domain so a domain with a
    // longer keyword list cannot win merely by having more chances to match.
    $scores = [];
    foreach ($domains as $domain => $pattern) {
        $score = 0;
        if (preg_match($pattern, $subjectText)) {
            $score += 50;
        }
        if (trim($scopeText) !== '' && preg_match($pattern, $scopeText)) {
            $score += 30;
        }
        if (trim($bodyText) !== '' && preg_match($pattern, $bodyText)) {
            $score += 20;
        }
        $scores[$domain] = $score;
    }
    // Strictly-greater keeps the original array order as the tie-break, so a
    // genuinely tied record resolves exactly as it did before this change.
    $best = '';
    $bestScore = 0;
    foreach ($scores as $domain => $score) {
        if ($score > $bestScore) {
            $best = $domain;
            $bestScore = $score;
        }
    }
    if ($best !== '') {
        return $best;
    }
    return $tag === 'infrastructure' ? 'operations' : 'general';
}

function pw_dispatch_draft_action_mode(string $clean): string
{
    if (preg_match('/^(?:add|create|introduce|include|enable|allow|expose|support|unlock)\b/i', $clean)) {
        return 'addition';
    }
    if (preg_match('/^(?:fix|resolve|repair|restore|prevent|secure|protect|harden|correct)\b/i', $clean)) {
        return 'correction';
    }
    return 'refinement';
}

function pw_dispatch_draft_diff_sentence(array $diffContext): string
{
    $files = (int)($diffContext['files_changed'] ?? 0);
    $areas = is_array($diffContext['areas'] ?? null) ? array_values(array_filter($diffContext['areas'])) : [];
    $extensions = is_array($diffContext['extensions'] ?? null) ? array_values(array_filter($diffContext['extensions'])) : [];
    if ($files <= 0) {
        return '';
    }
    $scope = $areas ? implode(' and ', array_slice($areas, 0, 2)) : ($extensions ? implode(' and ', array_slice($extensions, 0, 2)) : 'supporting site files');
    return 'Total files edited: ' . $files . ' in ' . $scope . '.';
}

/**
 * Build a compact, reader-safe plan before rendering Dispatch prose. The plan
 * deliberately contains only allow-listed scopes and evidence labels: raw
 * paths, source code, and commit-body text never become reader-facing copy.
 */
function pw_dispatch_draft_plan(string $text, string $tag, array $diffContext, array $spacyAnalysis, string $intentText = '', string $bodyText = ''): array
{
    $areas = is_array($diffContext['areas'] ?? null) ? array_values(array_filter($diffContext['areas'], 'is_string')) : [];
    $extensions = is_array($diffContext['extensions'] ?? null) ? array_values(array_filter($diffContext['extensions'], 'is_string')) : [];
    // $text is the subject-derived wording; the body is kept separate so the
    // domain scorer can weight a deliberate subject mention above incidental
    // supporting detail. Callers that pass no body get the old single-text
    // behaviour, with everything treated at subject weight.
    $domain = pw_dispatch_draft_domain($text, $tag, $diffContext, $bodyText);
    $semanticDomain = pw_dispatch_spacy_semantic_domain($spacyAnalysis);
    // Vectors may resolve only a genuinely unclassified commit. A clear local
    // domain match (for example, a named world or map) must never be replaced
    // merely because the broad Git tag says "refactor" or "infrastructure".
    if ($semanticDomain !== '' && $domain === 'general') {
        $domain = $semanticDomain;
    }

    return [
        'intent' => pw_dispatch_draft_action_mode($intentText !== '' ? $intentText : $text),
        'domain' => $domain,
        'semantic_domain' => $semanticDomain,
        'scopes' => array_slice($areas ?: $extensions, 0, 2),
        'has_path_scope' => !empty($areas) || !empty($extensions),
        'files_changed' => max(0, (int)($diffContext['files_changed'] ?? 0)),
    ];
}

function pw_dispatch_draft_nearest_similarity(array $spacyAnalysis): float
{
    $semanticSimilarity = isset($spacyAnalysis['nearest_similarity']) ? (float)$spacyAnalysis['nearest_similarity'] : 0.0;
    $semanticSimilarity = $semanticSimilarity >= 0.0 && $semanticSimilarity <= 1.0 ? $semanticSimilarity : 0.0;
    $fuzzySimilarity = isset($spacyAnalysis['nearest_fuzzy_similarity']) ? (float)$spacyAnalysis['nearest_fuzzy_similarity'] : 0.0;
    $fuzzySimilarity = $fuzzySimilarity >= 0.0 && $fuzzySimilarity <= 100.0 ? ($fuzzySimilarity / 100) : 0.0;
    // Both values only select another already-approved wording variant; they
    // are never confidence evidence and never alter publication eligibility.
    return max($semanticSimilarity, $fuzzySimilarity);
}

/**
 * The single list of recognized commit action verbs, shared by the
 * action-opening test and the object-phrase guard below so the two can never
 * drift apart. Multi-word entries are intentional ("speed up", "cross link").
 */
function pw_dispatch_action_verbs(): string
{
    return 'add|create|introduce|include|fix|resolve|repair|restore|improve|enhance|refine|polish|streamline|redesign|rework|restructure|expand|keep|show|align|widen|enlarge|split|stack|make|throttle|reduce|defer|slow|prevent|reserve|use|switch|load|deliver|cross link|connect|unlock|bump|optimi[sz]e|speed up|update|refresh|remove|retire|delete|move|reorganize|reorganise|reposition|secure|protect|harden|strengthen|color code|give|respect|clear|place|confine|pin|anchor|animate|preserve|preload|tighten|elevate|complete|alert|index|bundle|limit|pause|cache|pre aggregate|bulk load|track|collapse|graph|log|group|mask|rename|surface|reorder|finalize|swap|render|force|mirror|theme|store|merge|replace|auto refresh|always refresh|right align|put|pull|un float|paginate|increase|version|trim|revert|relative|sortable|styled|subtle|tiered|full|compact|label|highlight|explore|sharpen|hide|implement|enable|allow|expose|adjust|correct|clarify|consolidate|standardize|standardise|simplify|migrate|integrate|validate|verify|review|audit|diagnose|stabilize|stabilise|modernize|modernise|address|ensure|support|avoid|guard|isolate|measure|monitor|prepare|document|describe';
}

/**
 * Remove a leading action verb from a phrase about to be used as the
 * reader-facing object. The action-template path strips the verb implicitly
 * through its own capture group, but the two fallback paths (the raw cleaned
 * title, and a spaCy-extracted phrase) did not -- so a subject whose verb has
 * no template, such as "Score Dispatch draft domains...", put that bare verb
 * straight into the noun slot. Recognized verbs come from the shared list
 * above, plus any lemma spaCy itself tagged as a VERB, which generalizes to
 * verbs the static list has never seen without hardcoding more of English.
 * The original phrase is kept whenever stripping would leave nothing useful.
 */
function pw_dispatch_strip_leading_action_verb(string $phrase, array $spacyAnalysis = []): string
{
    $phrase = trim($phrase);
    if ($phrase === '' || !preg_match('/^([A-Za-z]+)\s+(.+)$/', $phrase, $matches)) {
        return $phrase;
    }
    $first = strtolower($matches[1]);
    $rest = trim($matches[2]);
    if (strlen($rest) < 3) {
        return $phrase;
    }
    if (preg_match('/^(?:' . pw_dispatch_action_verbs() . ')$/i', $first)) {
        return $rest;
    }
    $lemmas = is_array($spacyAnalysis['actions'] ?? null) ? $spacyAnalysis['actions'] : [];
    foreach ($lemmas as $lemma) {
        if (is_string($lemma) && strtolower(trim($lemma)) === $first) {
            return $rest;
        }
    }
    return $phrase;
}

/**
 * A spaCy-extracted phrase may only be used as the reader-facing object when
 * it actually comes from the commit *subject*. spaCy analyses subject and body
 * together, and its entity labels (WORK_OF_ART, PRODUCT, ORG) readily match a
 * quoted title inside a body -- which is how the commit "Score Dispatch draft
 * domains instead of first match" published the object "expand the Dispatch",
 * lifted verbatim out of a sentence in its own body that quoted the previous
 * commit's title. The body is a confidence signal only and must never reach
 * reader-facing copy, per the contract stated where $bodyContext is built.
 */
function pw_dispatch_spacy_object_is_grounded(string $candidate, string $subjectText): bool
{
    $normalize = static function (string $value): string {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    };
    $candidate = $normalize($candidate);
    $subjectText = $normalize($subjectText);
    if ($candidate === '' || $subjectText === '') {
        return false;
    }
    return strpos($subjectText, $candidate) !== false;
}

function pw_dispatch_end_user_draft(string $subject, string $body, string $tag, array $options = []): array
{
    $diffContext = is_array($options['diff_context'] ?? null) ? $options['diff_context'] : [];
    $recentTranslations = is_array($options['recent_translations'] ?? null) ? $options['recent_translations'] : [];
    $spacyAnalysis = is_array($options['spacy_analysis'] ?? null) ? $options['spacy_analysis'] : [];
    // Pre-computed by pw_dispatch_draft_options_for_dispatch() (a single
    // sentence-embedding lookup against the cached approved-translation
    // corpus) so this formatter itself stays a pure function the regression
    // harness can test with a synthetic value -- see tools/test-dispatch-translator.php.
    $embeddingMatch = is_array($options['embedding_match'] ?? null) ? $options['embedding_match'] : [];
    $embeddingSimilarity = isset($embeddingMatch['score']) ? (float)$embeddingMatch['score'] : 0.0;
    $clean = trim($subject);
    $clean = preg_replace('/^(?:feat(?:ure)?|fix|perf(?:ormance)?|refactor|chore|docs?|style|test)(?:\([^)]*\))?!?:\s*/i', '', $clean);
    $clean = preg_replace('/\s*\(?#[0-9]+\)?\s*$/', '', $clean);
    $clean = str_replace('_', ' ', $clean);
    $clean = preg_replace('/([a-z])\-([a-z])/i', '$1 $2', $clean);
    $clean = preg_replace('/\s*\+\s*/', ' and ', $clean);
    $clean = preg_replace('/\s*&\s*/', ' and ', $clean);
    $contextSource = $clean;
    // Legacy commits often have a terse subject but a useful explanatory body.
    // Treat the body as an additional, independently recognizable context signal;
    // it shapes confidence only and is never copied verbatim into reader copy.
    $bodyContext = trim(preg_replace('/\s+/', ' ', str_replace(['_', '+', '&'], [' ', ' and ', ' and '], $body)));
    $rulesMatched = 0;
    $evidence = [
        'recognized_subject' => false,
        'reader_safe_dictionary' => false,
        'reviewed_fuzzy_concept' => false,
        'commit_intent' => false,
        'body_context' => false,
        'path_scope' => false,
        'semantic_context' => false,
    ];
    $fuzzyConceptUsed = false;

    // Many legacy commits use a human-readable area before the description,
    // such as "Admin Home: add…" or "Language history: 24h refresh…".
    // Separating that area from the actual change gives the draft formatter
    // a reliable description to work with, even when the title has no verb.
    $scopedCommit = '/^[A-Za-z][A-Za-z0-9 .&\/()\-]{1,60}:\s+(.+)$/';
    $actionOpening = '/^(?:' . pw_dispatch_action_verbs() . ')\b/i';
    if (!preg_match($actionOpening, $clean) && preg_match($scopedCommit, $clean, $scopeMatches)) {
        $rulesMatched++;
        $evidence['recognized_subject'] = true;
        $clean = $scopeMatches[1];
    }
    // Keep the original action before a reader-safe replacement turns a long
    // technical title into a concise noun phrase (for example, "Unlock…" to
    // a named world and map). The plan still needs the real commit intent.
    $intentSource = $clean;

    // These are editorial substitutions, not opaque technical word removal.
    // They retain the commit's meaning while speaking in the language readers
    // encounter on the site. The most specific replacements come first.
    $replacements = [
        '/\bPreserve action intent across all Dispatch title rewrites\b/i' => 'how technical updates retain their intended meaning in reader-facing summaries',
        '/\bMatch capitalized world-release commit titles\b/i' => 'how new world releases are recognized in reader-facing summaries',
        '/\bWrite concrete world-release Dispatch summaries\b/i' => 'clearer public updates for new world releases',
        '/\bPrioritize explicit worldbuilding evidence in Dispatch drafts\b/i' => 'how development updates recognize worldbuilding work',
        '/\bPrevent vector domains from overriding clear Dispatch context\b/i' => 'the safeguards that keep development summaries on the right topic',
        '/\bStrengthen Dispatch translation evidence engine\b/i' => 'the evidence checks behind reader-facing development summaries',
        '/\bUse spaCy vectors for clearer Dispatch drafts\b/i' => 'local language analysis for development summaries',
        '/\bFix spaCy worker launch on shared hosting\b/i' => 'the local language helper used for development summaries',
        '/\bMonitor spaCy from Security and Scripts\b/i' => 'visibility into the local language helper',
        '/\bPolish the signed-in profile navigation\b/i' => 'the profile menu for signed-in members',
        '/\bAdd contextual hover detail to navigation panels\b/i' => 'helpful context in navigation panels',
        '/\bElevate public navigation hierarchy and discovery\b/i' => 'clearer ways to explore the public site',
        '/\bGive notification navigation a card treatment\b/i' => 'a clearer presentation for notifications',
        '/\bRefresh account navigation without idle delay\b/i' => 'faster account-state updates in the site navigation',
        '/\bHide unavailable Google unlink action\b/i' => 'clearer Google sign-in controls',
        '/\bAdd Google OAuth authentication\b/i' => 'the option to sign in or register with Google',
        '/\bAdd revocable user session management\b/i' => 'a way for members to review and revoke active sessions',
        '/\bAdd crawler labels to visitor statistics\b/i' => 'a clearer distinction between search crawlers and human visitors',
        '/\bHarden browser security policy\b/i' => 'stronger browser protections for the site',
        '/\bKeep CSP compatible with host rewriting\b/i' => 'browser protections that remain compatible with the hosting environment',
        '/\bColor-code audit activity icons\b/i' => 'clearer visual signals in the Audit Log',
        '/\bPrioritize BH-4 backup reviews\b/i' => 'earlier attention for backup reviews',
        '/\bEscalate stale backups through BH-4\b/i' => 'clearer escalation when a backup needs attention',
        '/\bAuto-publish high-confidence Dispatch translations\b/i' => 'faster publication of well-supported development summaries',
        '/\bAdd Translation Confidence Statistics to Admin Home\b/i' => 'a clearer overview of development-summary confidence',
        '/\bKeep the dispatches sidebar label on one line\b/i' => 'a clearer Development Dispatches label in the sidebar',
        '/\bPolish the admin sidebar and add personal navigation settings\b/i' => 'the Admin Console sidebar and personal navigation settings',
        '/\bLoad CSS bundles by page audience\b/i' => 'page-specific styling delivery',
        '/\bIntroduce shared CSS design tokens\b/i' => 'a more consistent visual foundation',
        '/\bRespect reduced-motion preferences site-wide\b/i' => 'a steadier experience for visitors who reduce motion',
        '/\bRestore active admin section after refresh\b/i' => 'the active Admin Console page after a refresh',
        '/\bAdd privacy request workflow and GDPR notice\b/i' => 'a clearer privacy-request path for members and visitors',
        '/\bPause presence heartbeats in hidden tabs\b/i' => 'background online-status checks when a tab is not visible',
        '/\bPre-aggregate visitor journey transitions\b/i' => 'how visitor journeys are prepared for faster analysis',
        '/\bBulk-load public world details\b/i' => 'how world details are prepared more efficiently for visitors',
        '/\bAdd visitor journey Sankey diagram\b/i' => 'a visual view of how visitors move between pages',
        '/\bRecognize reviewed Dispatch vocabulary in confidence\b/i' => 'how reviewed development terminology supports clearer summaries',
        '/\bLink auto-published translations to public dispatches\b/i' => 'a direct route from approved development summaries to their public records',
        '/\bCenter Google authentication control\b/i' => 'a more balanced Google sign-in control',
        '/\bFlatten Google sign-in emblem\b/i' => 'a simpler Google sign-in emblem that fits the site theme',
        '/\bRedirect all HTTP traffic to HTTPS\b/i' => 'a safer secure connection for every visit',
        '/\bEnrich Dispatch Draft Translator context\b/i' => 'clearer context for reader-facing development summaries',
        '/\bFill missing audit activity icons\b/i' => 'complete visual markers for Audit Log activity',
        '/\bRecognize more low-confidence Dispatch drafts\b/i' => 'clearer recognition of reader-safe development updates',
        '/\bShorten the Dispatch developer record label\b/i' => 'a clearer label for the technical development record',
        '/\bGraphical polish pass on the forum\b/i' => 'a visual refinement pass for forum discussions',
        '/\bAdmin Members: avatar and role ring in list rows, generate password button\b/i' => 'clearer member administration controls',
        '/\bBH-4 welcome card: bigger portrait, stack the stat rows\b/i' => 'a clearer BH-4 status presentation on the Admin Home page',
        '/\bTopic Reports View link 404s\b/i' => 'the Topic Reports review link',
        '/\bban Permanent\/Temporary radios visible before checkbox is checked\b/i' => 'the member-ban controls',
        '/\bavatar row and meta panel staying visible in Create Member modal\b/i' => 'the Create Member form',
        '/\bAdmin sidebar: collapsible nav categories, System group for Audit Log, tighter spacing\b/i' => 'a more focused Admin Console sidebar',
        '/\bAdmin Home: terminal-style activity log and refresh button\b/i' => 'a clearer activity view on the Admin Home page',
        '/\bAdmin console: Home page with activity log\b/i' => 'the Admin Home activity view',
        '/\bMetric cards: clickable modal with Latest dispatches, Trend vs previous period, BH-4 verified badge\b/i' => 'a detailed view of current metrics and recent Dispatches',
        '/\bLanguage history: 24h refresh cadence, stacked bar chart with day\/week\/month\/year filter\b/i' => 'a clearer language-history view with flexible time ranges',
        '/\bAdmin console tweaks and dispatch footer reorder\b/i' => 'a more consistent Admin Console and Dispatch layout',
        '/\bBH-4 verified easter egg on Development Dispatches\b/i' => 'a small BH-4 detail on Development Dispatches',
        '/\bred Fix tag color and per-category icons on dispatch tags\b/i' => 'clearer category signals on Development Dispatches',
        '/\bwebhook committed at was converted to server timezone\b/i' => 'the correct ordering of Development Dispatch records',
        '/\blike\/dislike reactions and zebra striping on Development Dispatches\b/i' => 'clearer reactions and easier-to-scan Development Dispatches',
        '/\bzebra striping and announcement emphasis for forum topic rows\b/i' => 'a clearer forum topic list',
        '/\bBH-4 newsletter image leaving empty space above it\b/i' => 'the BH-4 newsletter presentation',
        '/\bterminal transition sequence on Re Sync Overlord Resonance click\b/i' => 'a more responsive Overlord Resonance interaction',
        '/\bper overlord 100% Overlord Resonance bar effects\b/i' => 'clearer Overlord Resonance status effects',
        '/\boverlord portraits and zebra striping on Quiz History rows\b/i' => 'a clearer Quiz History view',
        '/\bCascade delete a topic\'s replies when the topic itself is deleted\b/i' => 'cleaner removal of forum discussions',
        '/\bCommunity: threaded replies \(2 deep\), reactions, pinning, Top Voices leaderboard\b/i' => 'richer ways for members to follow and take part in forum discussions',
        '/\bCommunity nav item becomes a category\b/i' => 'a clearer route into the Nexus Veil community',
        '/\bsurface error log candidate paths in errors\.php response\b/i' => 'a focused review of available site error diagnostics',
        '/\badd one click copy button for raw commit message\b/i' => 'a quick way to copy the original development note',
        '/\b24h refresh cadence, stacked bar chart with day\/week\/month\/year filter\b/i' => 'a clearer language-history view with flexible time ranges',
        '/\bstyled category pill, GitHub link, zebra rows\b/i' => 'clearer Dispatch Control labels, source links, and list rows',
        '/\b500 error from duplicate named PDO placeholder\b/i' => 'a Dispatch search error',
        '/\bshow match % on each Quiz History row\b/i' => 'the match percentage on each Quiz History entry',
        '/\bSharpen High Hammer map \(unsharp mask and higher quality JPEG\) to reduce blur\b/i' => 'a sharper High Hammer map for easier exploration',
        '/\bHide default scrollbar arrow buttons for cleaner themed look\b/i' => 'a cleaner themed scrollbar',
        '/\bRestructure admin sidebar nav: Home category, moved Roles and Permissions, larger category labels\b/i' => 'a clearer Admin Console navigation structure',
        '/\bTemporary diagnostic endpoint: explore CPU\/DB introspection options\b/i' => 'a focused review of system monitoring options',
        '/\bAdmin Home: compact Recent Activity widget \(5 entries\) and new Audit Log page\b/i' => 'a compact recent-activity view and direct Audit Log access',
        '/\bMetric cards: clickable modal with Latest dispatches, Trend vs previous period, BH 4 verified badge\b/i' => 'a detailed view of current metrics and recent Dispatches',
        '/\bCreate deploy\.production\.yml\b/i' => 'the production deployment process',
        '/\breposition BH 4 badge beside the log, popup closes only via X\b/i' => 'the BH-4 status badge and its review panel',
        '/\baction type filter to the Audit Log page\b/i' => 'a clearer way to filter activity in the Audit Log',
        '/\bWiden error log candidate paths after live diagnostic\b/i' => 'system diagnostic coverage',
        '/\bPending Work card \(dispatches awaiting translation\)\b/i' => 'a Pending Work overview for Dispatches awaiting translation',
        '/\bbrowser security headers\b/i' => 'additional browser-level protections for site services',
        '/\bhover tooltips explaining each of the 15 writing phases on book progress bars\b/i' => 'clearer explanations for book-writing progress',
        '/\bCerius as a fully built world \(below Asmecu\)\b/i' => 'Cerius as a fully developed world to explore',
        '/\bSettings link to the logged in user\'s nav dropdown\b/i' => 'a direct link to member settings',
        '/\bDevelopment Dispatches page with GitHub webhook auto sync\b/i' => 'a synchronized Development Dispatches page',
        '/\bWorld Control list rows: large title, Edit button, status\/overlord pills\b/i' => 'clearer World Control entries with status information',
        '/\bQuick Actions card and give Add Roles to Member its own icon\b/i' => 'a clearer set of Admin Console quick actions',
        '/\bLast Backup row to System Status\b/i' => 'a visible record of the latest backup',
        '/\bcross link Development Dispatches and Development Metrics\b/i' => 'clearer connections between Dispatches and development metrics',
        '/\bReddit share on news posts, X\/Mastodon\/WhatsApp share on quiz result, and first chapter preview page\b/i' => 'more ways to share site content and preview the first chapter',
        '/\bIP throttling, CSRF, HIBP, idle timeout, security headers\b/i' => 'stronger safeguards for member sign-in and sessions',
        '/\bfooter "Explore" list collapsible on mobile\b/i' => 'a more compact Explore menu on mobile screens',
        '/\bmetrics link \/ BH 4 badge overlapping pagination on mobile\b/i' => 'the mobile layout around metrics and BH-4 status',
        '/\bvisible divider between world detail sections\b/i' => 'clearer separation between world detail sections',
        '/\bSQL performance diagnostics to System Status\b/i' => 'performance diagnostics in System Status',
        '/\bBH 4 welcome card: bigger portrait, stack the stat rows\b/i' => 'a clearer BH-4 welcome overview',
        '/\bsite wide Statistics and Development Snapshot cards to Home\b/i' => 'site-wide statistics and a Development Snapshot on Home',
        '/\bUnlock Asmecu on the worlds page with a full district map\b/i' => 'Asmecu and its full district map',
        '/\bQuote\/Like next to the kebab; always show the kebab; attribute quotes\b/i' => 'clearer quote and reaction controls in forum discussions',
        '/\busername search filter to the Audit Log\b/i' => 'a username search in the Audit Log',
        '/\bNeoh intro copy: five tiers, not six, now that Vault 17 is nested\b/i' => 'the Neoh introduction so its five tiers are described accurately',
        '/\bforum post cards with a profile section\b/i' => 'forum post cards with clearer member context',
        '/\bAdd Community Metrics card to Home and fix admin role badge colors\b/i' => 'a Community Pulse overview and clearer admin role indicators',
        '/\bAdd a Total Lines of Code tile with a daily delta to the admin Home page\b/i' => 'a daily codebase progress indicator on the Admin Home dashboard',
        '/\bAdd BH 4 Task Advisor: deterministic priority recommendation on the Home dashboard\b/i' => 'a BH-4 priority recommendation on the Home dashboard',
        '/\bAdd a UTC clock to the admin console\b/i' => 'a shared UTC time display in the Admin Console',
        '/\bAdd Notification Settings tab with per type opt out checkboxes\b/i' => 'notification preferences that members can control',
        '/\bAdd member system: PHP and MySQL login\/register\/session, community discussion board, profile page with saved quiz results\b/i' => 'a member area with sign-in, community discussions, profiles, and saved quiz results',
        '/\bFix chapter one\.html hero staying on Book One when preview unavailable\b/i' => 'the Book One preview header when a preview is unavailable',
        '/\bN\+1 queries?\b/i' => 'repeated database work',
        '/\bcomposite indexes?\b/i' => 'database performance',
        '/\bsession check\b/i' => 'online status updates',
        '/\bheartbeat requests?\b/i' => 'background online-status checks',
        '/\bCore Web Vitals?\b/i' => 'page loading experience',
        '/\bLCP\b/i' => 'main page loading',
        '/\bCSS\b/i' => 'visual styling',
        '/\bJavaScript\b|\bJS\b/i' => 'interactive behaviour',
        '/\bAPI\b|\bendpoint\b/i' => 'site service',
        '/\bSQL\b|\bMySQL\b|\bMariaDB\b|\bquer(?:y|ies)\b/i' => 'database',
        '/\bcach(?:e|ing)\b/i' => 'repeat-visit performance',
        '/\bwebhook\b/i' => 'repository update delivery',
        '/\bcron\b/i' => 'scheduled maintenance',
        '/\bUI\/UX\b|\bUI\b/i' => 'interface',
        '/\bAdmin Console\b/i' => 'Admin Console',
        '/\blogout\b/i' => 'sign-out experience',
        '/\bavatar\b/i' => 'member avatar',
        '/\bprofile\b/i' => 'member profile',
        '/\bsign-out experience action\b/i' => 'sign-out experience',
        '/\bshared styling\b/i' => 'consistent visual styling',
        '/\bImprove rule based Dispatch draft wording\b/i' => 'the wording used in end-user summaries for development updates',
        '/\bExpand Dispatch draft copy with reader facing context\b/i' => 'end-user summaries for development updates',
        '/\brule based Dispatch translation drafts\b/i' => 'a clearer local drafting process for development updates',
        '/\bAdmin Home card baseline treatment\b/i' => 'the default styling of Admin Home cards',
        '/\bAdmin Home visual polish\b/i' => 'the visual treatment of the Admin Home dashboard',
        '/\bdispatches sidebar label\b/i' => 'the Development Dispatches label in the sidebar',
        '/\bpersonal navigation settings\b/i' => 'personal navigation settings',
        '/\bpresence heartbeat writes\b/i' => 'how often online status is recorded',
        '/\bCSS bundles by page audience\b/i' => 'page-specific styling delivery',
        '/\bTotal Lines of Code tile with a daily delta\b/i' => 'a daily codebase progress indicator',
        '/\bBH 4 Task Advisor\b/i' => 'the BH-4 priority advisor on the Home dashboard',
        '/\bCommunity Metrics card\b/i' => 'the Community Metrics overview on the Home dashboard',
        '/\bnotification bell interaction\b/i' => 'the notification bell experience',
        '/\bNotification Settings tab with per type opt out checkboxes\b/i' => 'notification preferences that members can control',
        '/\bUTC clock\b/i' => 'the shared UTC time display',
        '/\beye glow position\b/i' => 'BH-4’s visual details',
        '/\bhero section padding\b/i' => 'Admin Console page spacing',
        '/\bresponsive cover stretching\b/i' => 'cover artwork on smaller screens',
        '/\bnon critical public images\b/i' => 'below-the-fold images',
        '/\blightbox\b/i' => 'full-screen image view',
        // Developer-slang glossary: general software-engineering jargon,
        // distinct from the project-specific commit titles above. These are
        // word/phrase-level swaps within an otherwise normal sentence, not
        // whole-subject replacements -- kept narrow to terms with one clear,
        // stable reader-safe meaning, matching the same review bar as every
        // other entry here. Conventional Commits prefixes (refactor:, chore:,
        // revert, etc.) are handled separately as commit-intent signals and
        // are not duplicated here.
        '/\bhot ?fix(?:es)?\b/i' => 'an urgent fix',
        '/\bWIP\b/i' => 'a work-in-progress change',
        '/\btech(?:nical)? debt\b/i' => 'accumulated maintenance work',
        '/\bboilerplate(?: code)?\b/i' => 'repetitive setup code',
        '/\bspaghetti code\b/i' => 'tangled, hard-to-follow code',
        '/\blegacy code\b/i' => 'older code',
        '/\brace conditions?\b/i' => 'a timing-dependent bug',
        '/\bmemory leaks?\b/i' => 'a memory-usage problem',
        '/\bedge cases?\b/i' => 'an unusual situation',
        '/\bsanity check(?:s|ing)?\b/i' => 'a basic verification step',
        '/\bsmoke tests?\b/i' => 'a basic verification test',
        '/\bflaky\b/i' => 'inconsistent',
        '/\bshims?\b/i' => 'a compatibility layer',
        '/\bpolyfills?\b/i' => 'a compatibility layer',
        '/\bscaffold(?:ing)?\b/i' => 'foundational setup',
        '/\bstubs?\b/i' => 'a placeholder',
        '/\bmocks?\b/i' => 'a test placeholder',
        '/\blint(?:ing)?\b/i' => 'code-style checking',
        '/\bbreaking changes?\b/i' => 'a change that affects existing behaviour',
        '/\bmerge conflicts?\b/i' => 'a conflicting change',
        '/\bmonkey[- ]?patch(?:ing|ed)?\b/i' => 'a targeted workaround',
        '/\bkludges?\b/i' => 'a temporary workaround',
        '/\bno-?ops?\b/i' => 'an inert placeholder step',
        '/\bidempotent\b/i' => 'safely repeatable',
        '/\bdebounc(?:e|ing|ed)\b/i' => 'reduced repeated triggering',
        '/\bthrottl(?:e|ing|ed)\b/i' => 'a rate-limited process',
        '/\bmiddleware\b/i' => 'a processing step',
        '/\bdeprecat(?:e|ed|es|ing|ion)\b/i' => 'phased out',
        '/\bregressions?\b/i' => 'a reintroduced issue',
        '/\brollback(?:s|ed)?\b/i' => 'a reversal of a recent change',
        // Interface-surface vocabulary. These are the names developers use for
        // parts of the Admin Console and public site that readers only ever
        // see, never name: across this repository's history "modal" appears in
        // 13 commit subjects, "dropdown" in 12, "tooltip" in 9 and "viewport"
        // in 4, none of which had any entry here -- so each one reached
        // reader-facing prose verbatim through $object. Replacements are
        // deliberately article-free ('pop-up panel', not 'a pop-up panel') so
        // they read correctly after whatever determiner the commit already
        // used ("the modal" -> "the pop-up panel"). Plural forms come first
        // because the loop rewrites $clean in place and the singular pattern
        // would otherwise leave a plural subject reading as singular.
        '/\bmodals\b/i' => 'pop-up panels',
        '/\bmodal\b/i' => 'pop-up panel',
        '/\bdrop ?downs\b/i' => 'expanding menus',
        '/\bdrop ?down\b/i' => 'expanding menu',
        '/\btooltips\b/i' => 'hover labels',
        '/\btooltip\b/i' => 'hover label',
        '/\bviewports?\b/i' => 'visible screen area',
        // Sign-in and safeguard acronyms. Each has exactly one stable meaning
        // in this project, so none of them can silently reinterpret an
        // unrelated record the way a broad word swap could.
        '/\bOAuth\b/i' => 'third-party sign-in',
        '/\bCSP\b/i' => 'browser content protections',
        '/\bCSRF\b/i' => 'request-forgery protection',
        '/\b2FA\b/i' => 'two-factor authentication',
        '/\bTOTP\b/i' => 'authenticator app codes',
        // Translation-pipeline vocabulary. "embedding(s)" appears in 9 commit
        // subjects, all of them recent, because the sentence-embedding
        // similarity feature is actively being worked on. The full
        // "sentence embedding semantic similarity" phrase is matched first so
        // the shorter patterns cannot stack into a redundant sentence. Note
        // these match the de-hyphenated form: the letter-hyphen-letter rule
        // near the top of this function has already turned "sentence-embedding"
        // into "sentence embedding" by the time the dictionary runs.
        '/\bsentence embedding semantic similarity\b/i' => 'meaning-based text comparison',
        '/\bsemantic embeddings?\b/i' => 'meaning-based text comparison',
        '/\bembeddings?\b/i' => 'meaning-based text comparison',
        // Operational jargon seen in recent maintenance commits.
        '/\bback ?fill(?:s|ed|ing)?\b/i' => 'past-record fill-in',
        '/\bstale\b/i' => 'out-of-date',
        '/\bdebug logging\b/i' => 'diagnostic recording',
        '/\bOOM\b/i' => 'running out of memory',
        '/\bproc[ _]open\b/i' => 'short-lived helper process',
    ];
    foreach ($replacements as $pattern => $replacement) {
        if (preg_match($pattern, $clean)) {
            // The dictionary counts as one formatter rule no matter how many
            // terms it rewrites. A subject containing several known words is
            // denser vocabulary, not independent corroborating evidence, and
            // $rulesMatched >= 2 is enough on its own to force a 65% score and
            // satisfy the high-confidence gate in pw_dispatch_draft_confidence()
            // -- so counting every word-level swap separately would let a
            // jargon-heavy subject auto-publish on vocabulary alone.
            if (!$evidence['reader_safe_dictionary']) {
                $rulesMatched++;
            }
            $evidence['recognized_subject'] = true;
            // A narrow, reviewed dictionary entry is separate evidence from a
            // generic subject match: it confirms that this exact technical
            // phrase has a stable, reader-safe meaning in this project.
            $evidence['reader_safe_dictionary'] = true;
            $clean = preg_replace($pattern, $replacement, $clean);
            continue;
        }
        // Older Dispatches often use an explicit area before a colon. The
        // scope parser correctly removes that technical prefix before prose is
        // rendered, but the curated dictionary still needs to see the complete
        // original title. A specific scoped match is safe to replace wholesale
        // because every entry is reviewed project vocabulary; stop at the
        // first (most specific) scoped match to avoid stacking substitutions.
        if (preg_match($pattern, $contextSource)) {
            if (!$evidence['reader_safe_dictionary']) {
                $rulesMatched++;
            }
            $evidence['recognized_subject'] = true;
            $evidence['reader_safe_dictionary'] = true;
            $clean = $replacement;
            break;
        }
    }
    // A very close alias match can rescue a minor typo or a differently
    // ordered version of a reviewed project concept. The output remains a
    // PHP-owned, allow-listed phrase. Unlike an exact dictionary rule, this
    // result always requires an editor before publication.
    $fuzzyConcept = pw_dispatch_fuzzy_concept_from_analysis($spacyAnalysis);
    if (!$evidence['reader_safe_dictionary'] && $fuzzyConcept !== null) {
        $rulesMatched++;
        $evidence['recognized_subject'] = true;
        $evidence['reviewed_fuzzy_concept'] = true;
        $clean = $fuzzyConcept['reader_object'];
        $fuzzyConceptUsed = true;
    }
    $clean = preg_replace('/\s+/', ' ', trim($clean));
    $clean = trim($clean, " .:-");

    // Do not expose a file path, a hash-like token, or an empty technical
    // subject to readers. The neutral wording deliberately avoids claims
    // about a feature when the source message cannot be safely rephrased.
    $unsafe = $clean === '' || preg_match('/(?:\b[0-9a-f]{7,40}\b|[\\\\\/]|\.php\b|\.js\b|\.css\b)/i', $clean);
    if ($unsafe) {
        // A file name alone is not reader-facing, but an otherwise meaningful
        // technical title can still be recognized safely. This preserves a
        // cautious medium/high score instead of treating every path reference
        // as opaque, while hashes and path-only titles remain low confidence.
        $technicalCue = preg_match('/\b(?:API|SQL|MySQL|MariaDB|database|session|cache|query|performance|security|header|cron|webhook|deployment|GitHub|font|image|asset|stylesheet)\b/i', $contextSource . ' ' . $bodyContext);
        if ($technicalCue) {
            $rulesMatched++;
            $diffSentence = pw_dispatch_draft_diff_sentence($diffContext);
            return [
                'draft' => 'BH-4 has completed a focused maintenance update to a supporting site service. '
                    . 'It reduces avoidable friction behind the scenes while keeping the reader-facing experience steady.'
                    . ($diffSentence !== '' ? "\n\n" . $diffSentence : ''),
                'confidence' => pw_dispatch_draft_confidence($rulesMatched, ['recognized_subject' => true, 'path_scope' => !empty($diffContext)]),
                'hash' => pw_dispatch_draft_hash($subject, $body, $tag, $diffContext),
                'requires_editor_review' => false,
                'best_semantic_match' => $embeddingMatch,
            ];
        }
        return [
            'draft' => 'This update contains internal maintenance and reliability improvements. It helps keep the site stable and ready for future changes.',
            'confidence' => pw_dispatch_draft_confidence(0),
            'hash' => pw_dispatch_draft_hash($subject, $body, $tag, $diffContext),
            'requires_editor_review' => false,
            'best_semantic_match' => $embeddingMatch,
        ];
    }

    // A stable hash chooses an alternate phrasing for each commit. This keeps
    // repeated categories from reading like boilerplate while ensuring that a
    // regenerate action does not make the same source commit drift randomly.
    $nearestSimilarity = pw_dispatch_draft_nearest_similarity($spacyAnalysis);
    $pickVariant = static function (array $variants, string $salt) use ($subject, $recentTranslations, $nearestSimilarity): string {
        $count = count($variants);
        // A highly similar recent translation starts at a different stable
        // variant. Exact phrase checks below still provide the final guard.
        $shift = $nearestSimilarity >= 0.80 && $count > 1 ? 1 : 0;
        $index = ((int) sprintf('%u', crc32($subject . '|' . $salt)) + $shift) % $count;
        for ($offset = 0; $offset < $count; $offset++) {
            $candidate = $variants[($index + $offset) % $count];
            if (!pw_dispatch_draft_phrase_is_recent($candidate, $recentTranslations)) {
                return $candidate;
            }
        }
        return $variants[$index];
    };
    $benefitLibrary = [
        'feature' => [
            'It gives visitors and community members a focused new part of the site to use.',
            'The addition fits into the existing site without asking readers to relearn familiar paths.',
            'It opens a useful new route through Pantheon Wars while keeping the experience coherent.',
            'The new capability is placed where members and visitors can find it naturally.',
            'This gives the site another practical piece of its growing public experience.',
        ],
        'improvement' => [
            'The affected area should now feel clearer and more consistent in everyday use.',
            'The change smooths an existing experience without changing its familiar purpose.',
            'It makes a routine part of the site easier to understand and use.',
            'This refinement keeps the surrounding experience aligned and dependable.',
            'The result is a more deliberate, less distracting path through the affected area.',
        ],
        'fix' => [
            'The affected area should now behave more consistently for visitors and staff.',
            'This removes an avoidable interruption while preserving the intended experience.',
            'Routine use of the affected feature is now more dependable.',
            'The correction restores the expected path without changing how the feature is meant to feel.',
            'It resolves a point of friction so the surrounding experience can remain steady.',
        ],
        'performance' => [
            'It reduces unnecessary work behind the scenes so the affected pages can remain responsive.',
            'The change helps the site use its resources more carefully as content and traffic grow.',
            'This makes routine loading work lighter without changing the visible experience.',
            'Visitors should see a steadier experience as the site handles more activity.',
            'The update removes avoidable delay from a frequently used path.',
        ],
        'ui_ux' => [
            'The interface is now easier to scan, navigate, and use with confidence.',
            'This makes the affected controls more legible without disturbing the established visual language.',
            'The change gives the interface a clearer rhythm for everyday use.',
            'It improves how information and actions are presented at a glance.',
            'The surrounding interface should now feel more intentional and easier to follow.',
        ],
        'lore' => [
            'Readers gain clearer context for exploring the world of Pantheon Wars.',
            'The update adds detail while keeping established story information intact.',
            'It gives the setting a more legible path for readers who want to explore further.',
            'This clarification helps the world remain rich without becoming harder to navigate.',
            'The added context supports a deeper reading of the setting and its places.',
        ],
        'infrastructure' => [
            'Routine site services now have a more dependable foundation for everyday use.',
            'The maintenance reduces avoidable risk in the systems that support future updates.',
            'This keeps background operations prepared for the next round of site work.',
            'The change strengthens a supporting service without introducing a visible disruption.',
            'It makes the site easier to maintain while keeping normal use steady.',
        ],
        'refactor' => [
            'No visible feature changes, but future improvements can now be delivered with more confidence.',
            'The underlying structure is clearer, making later work safer to review and extend.',
            'This maintenance removes complexity from the path that future updates will use.',
            'The work keeps the same experience in place while giving it a cleaner foundation.',
            'It prepares the affected area for later changes without altering its present purpose.',
        ],
        'experimental' => [
            'This is a measured early improvement that can be refined after review in use.',
            'The change is intentionally contained so it can be observed and adjusted responsibly.',
            'It tests a focused direction while keeping the existing experience stable.',
            'This creates room to learn from real use before extending the idea further.',
            'The trial remains deliberately narrow, with a clear path for later refinement.',
        ],
    ];
    $benefit = $pickVariant($benefitLibrary[$tag] ?? [
        'It helps keep the site clear, reliable, and ready for future updates.',
    ], 'category');
    $contextLibrary = [
        '/\b(?:security|sign in|sign out|session|password|CSRF|IP throttling|IP address|login|account|privacy|GDPR|data request)\b/i' => [
            'BH-4 notes that the change also narrows an avoidable point of risk for member activity.',
            'The affected path is now better prepared to protect normal member activity.',
        ],
        '/\b(?:forum|community|topic|reply|member|notification|like|quote|mention|reaction|moderator|profile|settings|email|nav)\b/i' => [
            'Community activity should be easier to follow without adding noise to everyday conversations.',
            'The change supports clearer participation for members and moderators alike.',
        ],
        '/\b(?:Admin|Audit Log|System Status|backup|dashboard|quick action|BH 4|UTC|clock|sidebar|role|permission|Dispatch(?: Control| Translations)?|Development Dispatch)\b/i' => [
            'Administrators gain a clearer operational view while routine work stays focused.',
            'The administrative path now presents the relevant information with less unnecessary searching.',
        ],
        '/\b(?:mobile|responsive|cover artwork|interface|layout|card|tooltip|badge|portrait|hero|header|padding|color|animation|image|keyboard|focus|reduced motion|lightbox|scrollbar|favicon|logo|stylesheet|styling)\b/i' => [
            'The affected view should remain easier to read across the screens visitors actually use.',
            'This keeps the presentation stable and legible as the available screen space changes.',
        ],
        '/\b(?:world|lore|book|chapter|Asmecu|Cerius|Neoh|overlord|affinity|resonance|Quiz History|map|soundtrack)\b/i' => [
            'Readers have a clearer route into the relevant part of the setting.',
            'The added detail is framed to support exploration without obscuring established information.',
        ],
        '/\b(?:database|performance|loading|cache|CSS|visual styling|image|metrics|query|page view|CPU|asset|font|rollup|webhook|sync|visitor|statistics|Sankey|journey|traffic|feed)\b/i' => [
            'BH-4 expects the affected path to handle routine demand with less overhead.',
            'The improvement supports a more responsive experience as activity increases.',
        ],
        '/\b(?:presence|heartbeat|storage|error log|Site Errors|documentation|CLAUDE|README|deploy|cPanel|Git|htaccess)\b/i' => [
            'The maintenance gives routine site operations a clearer and more dependable foundation.',
            'BH-4 records a useful improvement to the systems that support future releases.',
        ],
    ];
    foreach ($contextLibrary as $pattern => $options) {
        if (preg_match($pattern, $contextSource . ' ' . $clean . ' ' . $bodyContext)) {
            $rulesMatched++;
            $evidence['body_context'] = $bodyContext !== '';
            // Context still strengthens confidence, but a second generic
            // benefit sentence often repeated the title without adding a
            // reader-facing fact. Keep the published copy concise instead.
            break;
        }
    }
    // A safe, descriptive title can still be useful to an editor even when it
    // uses an uncommon verb. Count that structure as one explicit rule, but
    // keep opaque values and bare technical tokens in the low-confidence path.
    if ($rulesMatched === 0 && preg_match_all('/[A-Za-z]{3,}/', $clean) >= 3) {
        $rulesMatched++;
    }
    $object = lcfirst($clean);
    $draft = '';
    $actionTemplates = [
        '/^add\s+(.+)\s+and\s+fix\s+(.+)$/i' => 'This update adds %s and corrects %s.',
        '/^(?:add|create|introduce|include)\s+(.+)$/i' => 'A new update adds %s.',
        '/^(?:fix|resolve|repair)\s+(.+)$/i' => 'This update fixes %s.',
        '/^(?:restore)\s+(.+)$/i' => 'This update restores %s.',
        '/^(?:improve|enhance|refine|polish|streamline)\s+(.+)$/i' => 'This update improves %s.',
        '/^(?:redesign|rework|restructure)\s+(.+)$/i' => 'This update gives %s a clearer structure and presentation.',
        '/^(?:expand)\s+(.+)$/i' => 'This update adds more detail to %s.',
        '/^(?:keep|show|align)\s+(.+)$/i' => 'This update keeps %s clear and easy to read.',
        '/^(?:widen|enlarge)\s+(.+)$/i' => 'This update gives %s more room to work clearly.',
        '/^(?:split|stack)\s+(.+)$/i' => 'This update organizes %s into a clearer view.',
        '/^(?:make)\s+(.+)$/i' => 'This update makes %s easier to use.',
        '/^(?:throttle|reduce|defer)\s+(.+)$/i' => 'This update reduces unnecessary %s.',
        '/^(?:slow)\s+(.+)$/i' => 'This update gives %s a more considered refresh schedule.',
        '/^(?:prevent)\s+(.+)$/i' => 'This update helps prevent %s.',
        '/^(?:reserve)\s+(.+)$/i' => 'This update reserves clear space for %s.',
        '/^(?:use|switch)\s+(.+)$/i' => 'This update standardizes %s.',
        '/^(?:load|deliver)\s+(.+)$/i' => 'This update delivers %s more efficiently.',
        '/^(?:cross link|connect)\s+(.+)$/i' => 'This update connects %s more clearly.',
        '/^(?:unlock)\s+(.+)$/i' => 'This update opens up %s for visitors to explore.',
        '/^(?:bump)\s+(.+)$/i' => 'This update refreshes the versioning for %s.',
        '/^(?:optimi[sz]e|speed up)\s+(.+)$/i' => 'This update makes %s faster and more reliable.',
        '/^(?:update|refresh)\s+(.+)$/i' => 'This update refreshes %s.',
        '/^(?:remove|retire|delete)\s+(.+)$/i' => 'This update removes %s.',
        '/^(?:move|reorganize|reorganise|reposition)\s+(.+)$/i' => 'This update reorganizes %s.',
        '/^(?:secure|protect|harden|strengthen)\s+(.+)$/i' => 'This update strengthens protection for %s.',
        '/^(?:color code|theme)\s+(.+)$/i' => 'This update gives %s clearer visual signals.',
        '/^(?:give|elevate)\s+(.+)$/i' => 'This update gives %s a more considered presentation.',
        '/^(?:respect|preserve)\s+(.+)$/i' => 'This update preserves %s for a more dependable experience.',
        '/^(?:clear|tighten)\s+(.+)$/i' => 'This update makes %s more focused and easier to follow.',
        '/^(?:place|pin|anchor)\s+(.+)$/i' => 'This update positions %s more deliberately.',
        '/^(?:confine)\s+(.+)$/i' => 'This update keeps %s contained where it is needed.',
        '/^(?:animate)\s+(.+)$/i' => 'This update adds measured motion to %s.',
        '/^(?:preload)\s+(.+)$/i' => 'This update prepares %s earlier in the loading process.',
        '/^(?:complete|finalize)\s+(.+)$/i' => 'This update completes %s.',
        '/^(?:alert|surface)\s+(.+)$/i' => 'This update brings %s into clearer operational view.',
        '/^(?:index)\s+(.+)$/i' => 'This update improves database support for %s.',
        '/^(?:bundle)\s+(.+)$/i' => 'This update combines %s into a more efficient delivery path.',
        '/^(?:limit)\s+(.+)$/i' => 'This update limits %s to the work that is still needed.',
        '/^(?:pause)\s+(.+)$/i' => 'This update pauses %s when they are not needed.',
        '/^(?:cache|cache bust)\s+(.+)$/i' => 'This update keeps %s current and efficient on repeat visits.',
        '/^(?:pre aggregate|bulk load)\s+(.+)$/i' => 'This update prepares %s more efficiently before they are needed.',
        '/^(?:track|log)\s+(.+)$/i' => 'This update records %s more clearly.',
        '/^(?:collapse)\s+(.+)$/i' => 'This update makes %s more compact without hiding its purpose.',
        '/^(?:graph)\s+(.+)$/i' => 'This update presents %s in a clearer visual form.',
        '/^(?:group)\s+(.+)$/i' => 'This update groups %s into a clearer view.',
        '/^(?:mask)\s+(.+)$/i' => 'This update protects %s from unnecessary exposure.',
        '/^(?:rename)\s+(.+)$/i' => 'This update gives %s a clearer name.',
        '/^(?:reorder|swap)\s+(.+)$/i' => 'This update reorganizes %s for easier use.',
        '/^(?:render)\s+(.+)$/i' => 'This update presents %s more clearly.',
        '/^(?:force)\s+(.+)$/i' => 'This update applies %s consistently.',
        '/^(?:mirror)\s+(.+)$/i' => 'This update aligns %s with the surrounding experience.',
        '/^(?:store)\s+(.+)$/i' => 'This update keeps %s available for reliable future use.',
        '/^(?:merge)\s+(.+)$/i' => 'This update brings %s together in one clearer place.',
        '/^(?:replace)\s+(.+)$/i' => 'This update replaces %s with a clearer approach.',
        '/^(?:auto refresh|always refresh)\s+(.+)$/i' => 'This update keeps %s current automatically.',
        '/^(?:right align)\s+(.+)$/i' => 'This update aligns %s more clearly.',
        '/^(?:put|pull)\s+(.+)$/i' => 'This update places %s where it is easier to use.',
        '/^(?:un float)\s+(.+)$/i' => 'This update keeps %s in the intended layout flow.',
        '/^(?:paginate)\s+(.+)$/i' => 'This update breaks %s into easier-to-browse pages.',
        '/^(?:increase)\s+(.+)$/i' => 'This update makes %s easier to notice and use.',
        '/^(?:version)\s+(.+)$/i' => 'This update keeps %s current when a new release is deployed.',
        '/^(?:trim)\s+(.+)$/i' => 'This update removes unnecessary space from %s.',
        '/^(?:revert)\s+(.+)$/i' => 'This update restores the intended presentation for %s.',
        '/^(?:relative)\s+(.+)$/i' => 'This update presents %s in a more useful time-based form.',
        '/^(?:sortable)\s+(.+)$/i' => 'This update makes %s easier to sort and review.',
        '/^(?:styled|subtle|tiered|full)\s+(.+)$/i' => 'This update refines the presentation of %s.',
        '/^(?:compact)\s+(.+)$/i' => 'This update makes %s more focused without removing useful detail.',
        '/^(?:label|highlight)\s+(.+)$/i' => 'This update makes %s clearer at a glance.',
        '/^(?:explore)\s+(.+)$/i' => 'This update reviews %s to prepare a more reliable next step.',
        '/^(?:sharpen)\s+(.+)$/i' => 'This update makes %s clearer to view.',
        '/^(?:hide)\s+(.+)$/i' => 'This update removes unnecessary visual clutter from %s.',
        '/^(?:implement|enable|allow|expose|support)\s+(.+)$/i' => 'This update makes %s available in a clearer, more reliable way.',
        '/^(?:adjust|correct|clarify)\s+(.+)$/i' => 'This update clarifies and improves %s.',
        '/^(?:consolidate|standardize|standardise|simplify|integrate)\s+(.+)$/i' => 'This update brings %s into a clearer, more consistent approach.',
        '/^(?:migrate)\s+(.+)$/i' => 'This update moves %s onto a more dependable foundation.',
        '/^(?:validate|verify|review|audit|diagnose|measure|monitor)\s+(.+)$/i' => 'This update gives %s a more reliable basis for review.',
        '/^(?:stabilize|stabilise|modernize|modernise|address|ensure|avoid|guard|isolate|prepare|document|describe)\s+(.+)$/i' => 'This update makes %s more dependable and easier to follow.',
    ];
    foreach ($actionTemplates as $pattern => $template) {
        $matchedSource = '';
        if (preg_match($pattern, $clean, $matches)) {
            $matchedSource = 'reader';
        } elseif ($clean !== $intentSource && preg_match($pattern, $intentSource, $matches)) {
            // A precise replacement can turn "Add …" or "Unlock …" into a
            // concise noun phrase. Preserve the original action while using
            // that safer phrase as the object. This is the engine-wide guard
            // against action verbs leaking into the reader-facing noun slot.
            $matchedSource = 'original';
        } else {
            continue;
        }
        $rulesMatched++;
        $evidence['commit_intent'] = true;
        $evidence['recognized_subject'] = true;
        $arguments = array_map(static function ($value): string {
            return rtrim(lcfirst(trim($value)), '.');
        }, array_slice($matches, 1));
        if ($matchedSource === 'original' && substr_count($template, '%s') === 1) {
            $arguments = [rtrim(lcfirst(trim($clean)), '.')];
        }
        $object = $arguments[0];
        $draft = vsprintf($template, $arguments);
        break;
    }

    $templates = [
        'feature' => 'A new update adds %s.',
        'improvement' => 'This update improves %s.',
        'fix' => 'This update improves reliability around %s.',
        'performance' => 'This update makes %s faster and more reliable.',
        'ui_ux' => 'This update refines the experience around %s.',
        'lore' => 'This update expands the worldbuilding around %s.',
        'infrastructure' => 'This update strengthens the reliability of %s.',
        'refactor' => 'This update improves the foundations supporting %s.',
        'experimental' => 'This update introduces an experimental improvement for %s.',
    ];
    $template = isset($templates[$tag]) ? $templates[$tag] : 'This update improves %s.';

    // spaCy contributes only a safe extracted phrase when the local action
    // library could not identify an object. It never changes the confidence
    // score or publication threshold; those remain explainable PHP rules.
    if ($draft === '') {
        $spacyObject = pw_dispatch_spacy_reader_object($spacyAnalysis);
        // Only accept it when the phrase genuinely comes from the subject --
        // spaCy sees the body too, and an entity match inside a body (a quoted
        // title, an internal note) must never become published reader copy.
        if ($spacyObject !== '' && pw_dispatch_spacy_object_is_grounded($spacyObject, $contextSource . ' ' . $clean)) {
            $object = lcfirst($spacyObject);
        }
        $object = pw_dispatch_strip_leading_action_verb($object, $spacyAnalysis);
        $draft = sprintf($template, rtrim($object, '.'));
    }

    // Commit-type templates give recurring domains their own BH-4 vocabulary.
    // The title still supplies the object, while the domain determines the
    // reader-facing intent: security work reads differently from community,
    // database, content, interface, or performance work.
    $plan = pw_dispatch_draft_plan($contextSource . ' ' . $clean, $tag, $diffContext, $spacyAnalysis, $intentSource, $bodyContext);
    $domain = $plan['domain'];
    $mode = $plan['intent'];
    $evidence['path_scope'] = $plan['has_path_scope'];
    // Either signal earns the same 5-point weight -- the sentence-embedding
    // match (real contextual similarity against approved past translations)
    // is a strictly stronger signal than spaCy's static-word-vector domain
    // classifier, but this pass deliberately keeps the existing weight
    // unchanged so a quality shift is attributable to the signal change
    // alone, not a re-tuned weight at the same time. See CLAUDE.md.
    $evidence['semantic_context'] = $plan['semantic_domain'] !== '' || $embeddingSimilarity >= 0.75;
    $naturalOverrideApplied = false;
    $naturalOverrides = [
        '/\b(?:action intent|Dispatch title rewrites|translator regression|reader-facing development summaries|Dispatch Draft Translator)\b/i' => [
            'draft' => [
                'BH-4 refined how development updates are prepared for readers.',
                'BH-4 strengthened the local process that prepares development updates for readers.',
            ],
            'benefit' => [
                'The original purpose of a change is now less likely to be lost when it is explained in plain language.',
                'This keeps the original purpose of technical work clear when it is explained in plain language.',
            ],
        ],
        '/\b(?:Google OAuth|sign in or register with Google|Google sign-in)\b/i' => [
            'draft' => [
                'BH-4 added a more direct Google sign-in path for members.',
                'BH-4 improved how members can use Google to enter their account.',
            ],
            'benefit' => [
                'Existing account safeguards and member settings remain part of the same familiar experience.',
                'The added option sits alongside the existing sign-in path rather than replacing it.',
            ],
        ],
        '/\b(?:revocable user session|active sessions?|session management)\b/i' => [
            'draft' => [
                'BH-4 added clearer control over active member sessions.',
                'BH-4 made it easier for members to review where their account is active.',
            ],
            'benefit' => [
                'Members can now remove sessions they no longer recognise or need.',
                'This gives account holders a more deliberate way to manage ongoing access.',
            ],
        ],
        '/\b(?:crawler labels?|search crawlers?|visitor statistics)\b/i' => [
            'draft' => [
                'BH-4 clarified which recent visits come from recognised search crawlers.',
                'BH-4 separated known indexing traffic from ordinary visitor records.',
            ],
            'benefit' => [
                'Visitor Statistics can now present human activity with clearer context.',
                'This improves analytics clarity without changing the privacy protections already in place.',
            ],
        ],
        '/\b(?:spaCy|spacy)\s+(?:worker|translation).*\b(?:launch|start|hosting|shared host)\b/i' => [
            'draft' => [
                'BH-4 improved how the Dispatch translation worker starts on the shared host.',
                'BH-4 restored the local start-up path for the Dispatch translation worker.',
            ],
            'benefit' => [
                'It can now load the language tools it needs before preparing reader-facing summaries.',
                'This keeps the translation service ready when new development records arrive.',
            ],
        ],
        '/\b(?:proc_open|virtualenv|Python environment|language model)\b/i' => [
            'draft' => [
                'BH-4 repaired a supporting path used by the local Dispatch translation service.',
                'BH-4 restored the local runtime that prepares Dispatch translation drafts.',
            ],
            'benefit' => [
                'Reader-facing summaries can continue to receive their intended language enrichment.',
                'The translation service is again ready to support incoming development records.',
            ],
        ],
    ];
    foreach ($naturalOverrides as $pattern => $copy) {
        if (preg_match($pattern, $contextSource . ' ' . $clean . ' ' . $bodyContext)) {
            $draft = $pickVariant($copy['draft'], 'natural-override');
            $benefit = $pickVariant($copy['benefit'], 'natural-benefit');
            $naturalOverrideApplied = true;
            break;
        }
    }
    // World releases deserve concrete reader copy, not the generic content
    // profile. Extract only facts stated in the commit: the named world, an
    // explicit map, a stated district count, and landmarks. This also prevents
    // an action verb such as "unlock" from being left inside an object phrase.
    if (!$naturalOverrideApplied && preg_match('/\bunlock\s+([A-Z][A-Za-z0-9-]{2,50})\b/i', $intentSource, $worldMatch)) {
        $worldName = $worldMatch[1];
        $worldText = $contextSource . ' ' . $clean . ' ' . $bodyContext;
        $hasMap = (bool)preg_match('/\b(?:district\s+)?map\b/i', $worldText);
        $districtCount = '';
        if (preg_match('/\b([2-9]|[1-9][0-9]+)\s+clickable\s+districts?\b/i', $worldText, $districtMatch)) {
            $districtCount = $districtMatch[1];
        }
        $hasLandmarks = (bool)preg_match('/\blandmarks?\b/i', $worldText);
        $draft = 'BH-4 has opened ' . $worldName . ' for exploration on the Worlds page.';
        if ($hasMap && $districtCount !== '') {
            $benefit = 'Visitors can now follow a full district map through ' . $districtCount . ' clickable districts' . ($hasLandmarks ? ' and key landmarks.' : '.');
        } elseif ($hasMap) {
            $benefit = 'Visitors can now follow a full district map' . ($hasLandmarks ? ' and discover its key landmarks.' : ' from the dedicated world record.');
        } else {
            $benefit = 'The dedicated world record is now available for readers to explore.';
        }
        $domain = 'content';
        $naturalOverrideApplied = true;
        $evidence['recognized_subject'] = true;
        $evidence['commit_intent'] = true;
        $evidence['body_context'] = $bodyContext !== '';
    }
    $domainTemplates = [
        'security' => [
            'addition' => ['BH-4 has added an extra safeguard for %s.', 'BH-4 has extended protection around %s.'],
            'correction' => ['BH-4 has repaired a protection issue around %s.', 'BH-4 has restored a safer path through %s.'],
            'refinement' => ['BH-4 has reinforced the safeguards around %s.', 'BH-4 has tightened the protection supporting %s.'],
        ],
        'database' => [
            'addition' => ['BH-4 has expanded database support for %s.', 'BH-4 has added clearer database coverage for %s.'],
            'correction' => ['BH-4 has corrected database work affecting %s.', 'BH-4 has repaired a data-service issue around %s.'],
            'refinement' => ['BH-4 has strengthened the database path behind %s.', 'BH-4 has made the data work supporting %s more deliberate.'],
        ],
        'performance' => [
            'addition' => ['BH-4 has added a lighter delivery path for %s.', 'BH-4 has introduced a more efficient route for %s.'],
            'correction' => ['BH-4 has removed avoidable delay around %s.', 'BH-4 has corrected a performance issue affecting %s.'],
            'refinement' => ['BH-4 has reduced avoidable work behind %s.', 'BH-4 has refined %s for a steadier response.'],
        ],
        'community' => [
            'addition' => ['BH-4 has opened a clearer community path for %s.', 'BH-4 has added a more useful community tool around %s.'],
            'correction' => ['BH-4 has repaired a community interaction around %s.', 'BH-4 has restored the expected community path for %s.'],
            'refinement' => ['BH-4 has made the community experience around %s easier to follow.', 'BH-4 has refined how members move through %s.'],
        ],
        'content' => [
            'addition' => ['BH-4 has made %s available for readers to explore.', 'BH-4 has opened a new reader route into %s.'],
            'correction' => ['BH-4 has corrected the reader-facing record for %s.', 'BH-4 has restored clearer context around %s.'],
            'refinement' => ['BH-4 has refined the reader-facing presentation of %s.', 'BH-4 has made the record around %s easier to explore.'],
        ],
        'interface' => [
            'addition' => ['BH-4 has added a clearer interface path for %s.', 'BH-4 has introduced a more legible view for %s.'],
            'correction' => ['BH-4 has repaired the interface behaviour around %s.', 'BH-4 has restored the intended presentation of %s.'],
            'refinement' => ['BH-4 has refined the interface around %s.', 'BH-4 has made %s clearer at a glance.'],
        ],
        'operations' => [
            'addition' => ['BH-4 has added a clearer operational record for %s.', 'BH-4 has expanded the site operations supporting %s.'],
            'correction' => ['BH-4 has repaired an operational issue around %s.', 'BH-4 has restored a dependable operational path for %s.'],
            'refinement' => ['BH-4 has clarified the operational support for %s.', 'BH-4 has strengthened the routine systems behind %s.'],
        ],
    ];
    if (!$naturalOverrideApplied && isset($domainTemplates[$domain][$mode])) {
        $draft = sprintf($pickVariant($domainTemplates[$domain][$mode], 'domain-' . $domain . '-' . $mode), rtrim($object, '.'));
    }

    // Each domain has its own restrained reader-facing voice. These benefits
    // stay factual and never claim a result that the commit evidence cannot
    // support; they simply avoid a security, performance, or community change
    // all sounding like the same generic maintenance note.
    $domainBenefits = [
        'security' => ['Member activity has a clearer layer of protection around this path.', 'The affected account or data path now carries a more deliberate safeguard.'],
        'database' => ['The supporting data work is now clearer and easier to maintain.', 'This gives the affected records a more dependable foundation.'],
        'performance' => ['The affected path now avoids work that visitors do not need to wait for.', 'This supports a steadier experience as routine activity grows.'],
        'community' => ['Members and moderators should find the affected interaction easier to follow.', 'The change supports clearer participation without adding noise to community activity.'],
        'content' => ['Readers have a clearer route into the affected part of the Pantheon Wars record.', 'The added context supports exploration while preserving the established setting.'],
        'interface' => ['The surrounding controls should now be easier to read and use at a glance.', 'The established visual language remains intact while the path becomes clearer.'],
        'operations' => ['The routine service behind this update now has a clearer operational foundation.', 'BH-4 records a more dependable path for the systems supporting future releases.'],
    ];
    if (!$naturalOverrideApplied && isset($domainBenefits[$domain])) {
        $benefit = $pickVariant($domainBenefits[$domain], 'voice-' . $domain);
    }

    $diffSentence = pw_dispatch_draft_diff_sentence($diffContext);
    if ($diffSentence !== '') {
        $rulesMatched++;
        $evidence['path_scope'] = true;
    }

    return [
        'draft' => $draft . ' ' . $benefit . ($diffSentence !== '' ? "\n\n" . $diffSentence : ''),
        'confidence' => pw_dispatch_draft_confidence($rulesMatched, $evidence),
        'plan' => $plan,
        'hash' => pw_dispatch_draft_hash($subject, $body, $tag, $diffContext),
        'requires_editor_review' => $fuzzyConceptUsed,
        // The single best-matching approved past Dispatch (or [] if none
        // scored above threshold), for the admin editor's reference panel.
        // Never used to alter wording -- PHP's templates above are already
        // finalized by this point.
        'best_semantic_match' => $embeddingMatch,
    ];
}

/**
 * Confidence is deliberately tied to explainable evidence, not to whether the
 * generated prose sounds plausible. The score separates recognized intent,
 * scope, body context, and optional semantic support so editors can see why a
 * draft deserves more or less scrutiny. spaCy can only add a small supporting
 * signal; it can never make an unsupported draft high confidence by itself.
 */
function pw_dispatch_draft_confidence(int $rulesMatched, array $evidence = []): array
{
    $weights = [
        'recognized_subject' => 25,
        'reader_safe_dictionary' => 10,
        'commit_intent' => 30,
        'body_context' => 10,
        'path_scope' => 20,
        'semantic_context' => 5,
    ];
    $score = 0;
    $matchedEvidence = [];
    foreach ($weights as $name => $weight) {
        if (!empty($evidence[$name])) {
            $score += $weight;
            $matchedEvidence[] = str_replace('_', ' ', $name);
        }
    }
    // Keep the established deterministic gate meaningful on older Dispatches
    // that predate stored diff context. Two formatter rules are still two
    // independent local signals, not an opaque model prediction.
    if ($rulesMatched >= 2 && $score < 65) {
        $score = 65;
        $matchedEvidence[] = 'independent formatter rules';
    } elseif ($score === 0 && $rulesMatched === 1) {
        $score = 30;
        $matchedEvidence[] = 'recognized formatter rule';
    }
    $independentSignals = count($matchedEvidence);
    if (!empty($evidence['reviewed_fuzzy_concept'])) {
        // This remains visible to the reviewer, but must not be counted as an
        // independent signal for high-confidence automatic publication.
        $matchedEvidence[] = 'reviewed fuzzy concept match';
    }
    $level = $score >= 65 && ($independentSignals >= 2 || $rulesMatched >= 2) ? 'high' : ($score >= 30 ? 'medium' : 'low');
    $labels = [
        'high' => 'High confidence',
        'medium' => 'Medium confidence',
        'low' => 'Low confidence',
    ];
    $explanation = $matchedEvidence
        ? ucfirst(implode(', ', $matchedEvidence)) . ' support this draft (' . $score . '% evidence score).'
        : 'No reliable intent, scope, or context evidence was identified.';

    if ($level === 'high') {
        return [
            'level' => 'high',
            'label' => $labels['high'],
            'rules_matched' => $rulesMatched,
            'score' => $score,
            'evidence' => $matchedEvidence,
            'explanation' => $explanation . ' Review for accuracy, then approve or edit as needed.',
        ];
    }
    if ($level === 'medium') {
        return [
            'level' => 'medium',
            'label' => $labels['medium'],
            'rules_matched' => $rulesMatched,
            'score' => $score,
            'evidence' => $matchedEvidence,
            'explanation' => $explanation . ' Check the draft carefully before publishing.',
        ];
    }

    return [
        'level' => 'low',
        'label' => $labels['low'],
        'rules_matched' => $rulesMatched,
        'score' => $score,
        'evidence' => $matchedEvidence,
        'explanation' => $explanation . ' Read and edit this draft carefully before publishing.',
    ];
}

/**
 * Summarize the current deterministic confidence rules across every Dispatch.
 * The weighted average is intentionally conservative: high-confidence drafts
 * score 100, medium 65, and low 25. The percentage distribution is kept
 * alongside it so the dashboard never hides a concentration of low matches.
 */
function pw_get_dispatch_translation_confidence_statistics(PDO $db): array
{
    $rows = $db->query('SELECT id, subject, body, tag FROM dispatch_entries')->fetchAll();
    $contexts = pw_get_dispatch_diff_contexts($db, array_column($rows, 'id'));
    $total = count($rows);
    $counts = ['high' => 0, 'medium' => 0, 'low' => 0];
    $weights = ['high' => 100, 'medium' => 65, 'low' => 25];
    $scoreTotal = 0;

    foreach ($rows as $row) {
        $confidence = pw_dispatch_end_user_draft($row['subject'], (string)$row['body'], $row['tag'], [
            'diff_context' => $contexts[(int)$row['id']] ?? [],
        ])['confidence'];
        $level = isset($counts[$confidence['level']]) ? $confidence['level'] : 'low';
        $counts[$level]++;
        $scoreTotal += $weights[$level];
    }

    $percent = static function (int $count) use ($total): int {
        return $total > 0 ? (int)round(($count / $total) * 100) : 0;
    };

    return [
        'ok' => true,
        'total_dispatches' => $total,
        'average_confidence' => $total > 0 ? (int)round($scoreTotal / $total) : 0,
        'high_percent' => $percent($counts['high']),
        'medium_percent' => $percent($counts['medium']),
        'low_percent' => $percent($counts['low']),
        'high_count' => $counts['high'],
        'medium_count' => $counts['medium'],
        'low_count' => $counts['low'],
    ];
}

// Bump the format version whenever wording rules change. Regenerate Draft then
// refreshes old unapproved drafts even when their source commit is unchanged.
function pw_dispatch_draft_hash(string $subject, string $body, string $tag, array $diffContext = []): string
{
    return hash('sha256', "dispatch-draft-v28\n" . $subject . "\n" . $body . "\n" . $tag . "\n" . json_encode($diffContext));
}

function pw_dispatch_draft_options_for_dispatch(PDO $db, int $dispatchId, string $subject = '', string $body = ''): array
{
    $contexts = pw_get_dispatch_diff_contexts($db, [$dispatchId]);
    $recentTranslations = [];
    try {
        $stmt = $db->prepare(
            'SELECT translation FROM dispatch_translations
             WHERE dispatch_id <> ? ORDER BY updated_at DESC, id DESC LIMIT 20'
        );
        $stmt->execute([$dispatchId]);
        $recentTranslations = array_column($stmt->fetchAll(), 'translation');
    } catch (PDOException $e) {
        // Draft creation already handles the translations table independently;
        // an empty guard is safer than preventing a webhook from completing.
    }
    // Only the current commit is ever encoded here; the corpus it's compared
    // against is the pre-computed cache in dispatch_translation_embeddings
    // (populated at publish/edit time by pw_dispatch_update_translation_embedding()),
    // so this never re-encodes any prior translation just to draft a new one.
    $embeddingMatch = [];
    if ($subject !== '' || $body !== '') {
        $encoded = pw_dispatch_embedding_similarity(trim($subject . "\n" . $body));
        if (!empty($encoded['embedding'])) {
            $embeddingMatch = pw_dispatch_nearest_embedding_match($db, $encoded['embedding'], $dispatchId);
        }
    }

    return [
        'diff_context' => $contexts[$dispatchId] ?? [],
        'recent_translations' => $recentTranslations,
        // The caller already loaded this Dispatch. Passing its text here avoids
        // introducing an extra database query just for optional NLP analysis.
        'spacy_analysis' => ($subject !== '' || $body !== '')
            ? pw_dispatch_spacy_analyze($subject, $body, $recentTranslations, pw_dispatch_fuzzy_worker_concepts())
            : [],
        'embedding_match' => $embeddingMatch,
    ];
}

// Webhook-created Dispatches have no signed-in administrator, but their
// translation state still belongs in the same audit trail. Use BH-4 as a
// clearly identifiable system actor and never let a nonessential audit write
// prevent a verified repository update from being processed.
function pw_log_dispatch_translation_lifecycle(PDO $db, string $action, string $description): void
{
    try {
        $stmt = $db->prepare(
            'INSERT INTO admin_activity_log (user_id, username, action, description, ip_address)
             VALUES (NULL, ?, ?, ?, ?)'
        );
        $stmt->execute(['BH-4', $action, $description, $_SERVER['REMOTE_ADDR'] ?? 'system']);
    } catch (PDOException $e) {
        // Audit logging must not make webhook delivery or manual resync fail.
    }
}

/**
 * Purely observational logging, never read back by the translator itself.
 * $previousText is whatever the engine had already suggested for this
 * dispatch immediately before this event -- a queued rule-based draft, a
 * previously published translation, or null when there was nothing to
 * compare against (e.g. a translation typed entirely from scratch). A null
 * $previousText logs a null similarity rather than a misleading 0% or 100%.
 * Best-effort: a missing migration or any failure here must never block a
 * publish or save.
 */
function pw_dispatch_log_translation_edit_event(PDO $db, int $dispatchId, string $event, ?string $previousText, string $newText): void
{
    $similarity = null;
    if ($previousText !== null && $previousText !== '') {
        similar_text($previousText, $newText, $percent);
        $similarity = round($percent, 2);
    }
    try {
        $stmt = $db->prepare(
            'INSERT INTO dispatch_translation_edit_events (dispatch_id, event, similarity_pct, previous_length, new_length)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $dispatchId,
            $event,
            $similarity,
            $previousText !== null ? mb_strlen($previousText, 'UTF-8') : 0,
            mb_strlen($newText, 'UTF-8'),
        ]);
    } catch (PDOException $e) {
        // Optional migration may not be applied yet.
    }
}

function pw_create_dispatch_translation_draft(PDO $db, int $dispatchId): array
{
    $entryStmt = $db->prepare('SELECT id, sha, subject, body, tag FROM dispatch_entries WHERE id = ?');
    $entryStmt->execute([$dispatchId]);
    $entry = $entryStmt->fetch();
    if (!$entry) {
        return ['ok' => false, 'reason' => 'missing'];
    }

    $approvedStmt = $db->prepare('SELECT 1 FROM dispatch_translations WHERE dispatch_id = ?');
    $approvedStmt->execute([$dispatchId]);
    if ($approvedStmt->fetch()) {
        return ['ok' => false, 'reason' => 'published'];
    }

    $draftOptions = pw_dispatch_draft_options_for_dispatch($db, $dispatchId, (string)$entry['subject'], (string)$entry['body']);
    $result = pw_dispatch_end_user_draft(
        $entry['subject'],
        (string)$entry['body'],
        $entry['tag'],
        $draftOptions
    );
    if ($result['confidence']['level'] === 'high' && empty($result['requires_editor_review'])) {
        // INSERT IGNORE deliberately avoids replacing an editor's approval if
        // one is written concurrently between the check above and this insert.
        $publishStmt = $db->prepare(
            'INSERT IGNORE INTO dispatch_translations (dispatch_id, sha, translation)
             VALUES (?, ?, ?)'
        );
        $publishStmt->execute([$dispatchId, $entry['sha'], $result['draft']]);
        if ($publishStmt->rowCount() > 0) {
            try {
                $deleteDraftStmt = $db->prepare('DELETE FROM dispatch_translation_drafts WHERE dispatch_id = ?');
                $deleteDraftStmt->execute([$dispatchId]);
            } catch (PDOException $e) {
                // A high-confidence publication is valid even on installations
                // that have not yet created the optional draft storage table.
            }
            pw_dispatch_update_translation_embedding($db, $dispatchId, $result['draft']);
            // Auto-publication is by definition zero human edit -- the engine's
            // own text becomes the final text -- so similarity is trivially 100%.
            pw_dispatch_log_translation_edit_event($db, $dispatchId, 'auto_published', $result['draft'], $result['draft']);
            pw_log_dispatch_translation_lifecycle(
                $db,
                'translation_auto_published',
                'BH-4 automatically published a high-confidence end-user translation for dispatch #' . $dispatchId . '.'
            );
            return [
                'ok' => true,
                'auto_published' => true,
                'translation' => $result['draft'],
                'confidence' => $result['confidence'],
                'best_semantic_match' => $result['best_semantic_match'] ?? [],
            ];
        }
        return ['ok' => false, 'reason' => 'published'];
    }

    $stmt = $db->prepare(
        'INSERT INTO dispatch_translation_drafts (dispatch_id, sha, draft, source, draft_hash)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           sha = VALUES(sha),
           draft = IF(draft_hash <> VALUES(draft_hash), VALUES(draft), draft),
           draft_hash = VALUES(draft_hash),
           source = VALUES(source)'
    );
    $stmt->execute([
        $dispatchId,
        $entry['sha'],
        $result['draft'],
        empty($draftOptions['spacy_analysis']) ? 'rule_based' : 'rule_based_spacy',
        $result['hash'],
    ]);
    if ($stmt->rowCount() === 1) {
        pw_log_dispatch_translation_lifecycle(
            $db,
            'translation_draft_waiting_review',
            'BH-4 created a ' . $result['confidence']['level'] . '-confidence end-user draft for dispatch #' . $dispatchId . '; editorial review is required before publication.'
        );
    }
    return [
        'ok' => true,
        'auto_published' => false,
        'draft' => $result['draft'],
        'confidence' => $result['confidence'],
        'best_semantic_match' => $result['best_semantic_match'] ?? [],
    ];
}
