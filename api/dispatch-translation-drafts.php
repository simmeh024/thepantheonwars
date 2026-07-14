<?php
/**
 * Local, deterministic copy formatter for end-user Dispatch drafts. It does
 * not call an external service. High-confidence results can be published
 * automatically; medium and low results remain in dispatch_translation_drafts
 * for an editor to approve or edit.
 */
require_once __DIR__ . '/dispatch-diff-context.php';

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

function pw_dispatch_draft_domain(string $text, string $tag, array $diffContext): string
{
    $haystack = $text . ' ' . implode(' ', $diffContext['areas'] ?? []) . ' ' . implode(' ', $diffContext['extensions'] ?? []);
    $domains = [
        'security' => '/\b(?:security|csrf|password|login|session|privacy|gdpr|permission|authentication|authorization|header|x-frame|referrer)\b/i',
        'database' => '/\b(?:database|sql|mysql|mariadb|query|index|migration|rollup)\b/i',
        'performance' => '/\b(?:performance|faster|speed|cache|loading|lcp|lighthouse|lazy|preload|defer|bandwidth|core web vitals)\b/i',
        'community' => '/\b(?:forum|community|topic|reply|comment|member|moderator|notification|report|reaction|profile)\b/i',
        'content' => '/\b(?:book|chapter|world|lore|overlord|dispatch|translation|story|map|character|quiz)\b/i',
        'interface' => '/\b(?:css|ui|ux|interface|layout|sidebar|card|modal|button|icon|image|hero|responsive|focus|animation)\b/i',
        'operations' => '/\b(?:admin|backup|system status|audit log|github|webhook|cron|deploy|monitor|analytics)\b/i',
    ];
    foreach ($domains as $domain => $pattern) {
        if (preg_match($pattern, $haystack)) {
            return $domain;
        }
    }
    return $tag === 'infrastructure' ? 'operations' : 'general';
}

function pw_dispatch_draft_action_mode(string $clean): string
{
    if (preg_match('/^(?:add|create|introduce|include|enable|allow|expose|support)\b/i', $clean)) {
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
    return 'The work spans ' . $files . ($files === 1 ? ' file in ' : ' files across ') . $scope . '.';
}

function pw_dispatch_end_user_draft(string $subject, string $body, string $tag, array $options = []): array
{
    $diffContext = is_array($options['diff_context'] ?? null) ? $options['diff_context'] : [];
    $recentTranslations = is_array($options['recent_translations'] ?? null) ? $options['recent_translations'] : [];
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

    // Many legacy commits use a human-readable area before the description,
    // such as "Admin Home: add…" or "Language history: 24h refresh…".
    // Separating that area from the actual change gives the draft formatter
    // a reliable description to work with, even when the title has no verb.
    $scopedCommit = '/^[A-Za-z][A-Za-z0-9 .&\/()\-]{1,60}:\s+(.+)$/';
    $actionOpening = '/^(?:add|create|introduce|include|fix|resolve|repair|restore|improve|enhance|refine|polish|streamline|redesign|rework|restructure|expand|keep|show|align|widen|enlarge|split|stack|make|throttle|reduce|defer|slow|prevent|reserve|use|switch|load|deliver|cross link|connect|unlock|bump|optimi[sz]e|speed up|update|refresh|remove|retire|delete|move|reorganize|reorganise|reposition|secure|protect|harden|strengthen|color code|give|respect|clear|place|confine|pin|anchor|animate|preserve|preload|tighten|elevate|complete|alert|index|bundle|limit|pause|cache|pre aggregate|bulk load|track|collapse|graph|log|group|mask|rename|surface|reorder|finalize|swap|render|force|mirror|theme|store|merge|replace|auto refresh|always refresh|right align|put|pull|un float|paginate|increase|version|trim|revert|relative|sortable|styled|subtle|tiered|full|compact|label|highlight|explore|sharpen|hide|implement|enable|allow|expose|adjust|correct|clarify|consolidate|standardize|standardise|simplify|migrate|integrate|validate|verify|review|audit|diagnose|stabilize|stabilise|modernize|modernise|address|ensure|support|avoid|guard|isolate|measure|monitor|prepare|document|describe)\b/i';
    if (!preg_match($actionOpening, $clean) && preg_match($scopedCommit, $clean, $scopeMatches)) {
        $rulesMatched++;
        $clean = $scopeMatches[1];
    }

    // These are editorial substitutions, not opaque technical word removal.
    // They retain the commit's meaning while speaking in the language readers
    // encounter on the site. The most specific replacements come first.
    $replacements = [
        '/\bsurface error log candidate paths in errors\.php response\b/i' => 'a focused review of available site error diagnostics',
        '/\badd one click copy button for raw commit message\b/i' => 'a quick way to copy the original development note',
        '/\b24h refresh cadence, stacked bar chart with day\/week\/month\/year filter\b/i' => 'a clearer language-history view with flexible time ranges',
        '/\bstyled category pill, GitHub link, zebra rows\b/i' => 'clearer Dispatch Control labels, source links, and list rows',
        '/\b500 error from duplicate named PDO placeholder\b/i' => 'a Dispatch search error',
        '/\bshow match % on each Quiz History row\b/i' => 'the match percentage on each Quiz History entry',
        '/\bSharpen High Hammer map \(unsharp mask and higher quality JPEG\) to reduce blur\b/i' => 'a sharper High Hammer map for easier exploration',
        '/\bHide default scrollbar arrow buttons for cleaner themed look\b/i' => 'a cleaner themed scrollbar',
        '/\bGraphical polish pass on the forum\b/i' => 'a visual refinement pass for forum discussions',
        '/\bRestructure admin sidebar nav: Home category, moved Roles and Permissions, larger category labels\b/i' => 'a clearer Admin Console navigation structure',
        '/\bTemporary diagnostic endpoint: explore CPU\/DB introspection options\b/i' => 'a focused review of system monitoring options',
        '/\bAdmin Members: avatar and role ring in list rows, generate password button\b/i' => 'clearer member administration controls',
        '/\bAdmin sidebar: collapsible nav categories, System group for Audit Log, tighter spacing\b/i' => 'a more focused Admin Console sidebar',
        '/\bAdmin Home: compact Recent Activity widget \(5 entries\) and new Audit Log page\b/i' => 'a compact recent-activity view and direct Audit Log access',
        '/\bMetric cards: clickable modal with Latest dispatches, Trend vs previous period, BH 4 verified badge\b/i' => 'a detailed view of current metrics and recent Dispatches',
        '/\bLanguage history: 24h refresh cadence, stacked bar chart with day\/week\/month\/year filter\b/i' => 'a clearer language-history view with flexible time ranges',
        '/\bCreate deploy\.production\.yml\b/i' => 'the production deployment process',
        '/\breposition BH 4 badge beside the log, popup closes only via X\b/i' => 'the BH-4 status badge and its review panel',
        '/\baction type filter to the Audit Log page\b/i' => 'a clearer way to filter activity in the Audit Log',
        '/\bWiden error log candidate paths after live diagnostic\b/i' => 'system diagnostic coverage',
        '/\bPending Work card \(dispatches awaiting translation\)\b/i' => 'a Pending Work overview for Dispatches awaiting translation',
        '/\bbrowser security headers\b/i' => 'additional browser-level protections for site services',
        '/\bhover tooltips explaining each of the 15 writing phases on book progress bars\b/i' => 'clearer explanations for book-writing progress',
        '/\bMetric cards: clickable modal with Latest dispatches, Trend vs previous period, BH 4 verified badge\b/i' => 'a detailed view of current metrics and recent Dispatches',
        '/\bCerius as a fully built world \(below Asmecu\)\b/i' => 'Cerius as a fully developed world to explore',
        '/\bCascade delete a topic\'s replies when the topic itself is deleted\b/i' => 'cleaner removal of forum discussions',
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
        '/\bLanguage history: 24h refresh cadence, stacked bar chart with day\/week\/month\/year filter\b/i' => 'a clearer language-history view with flexible time ranges',
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
        '/\bPolish the admin sidebar and add personal navigation settings\b/i' => 'the Admin Console sidebar and personal navigation settings',
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
    ];
    foreach ($replacements as $pattern => $replacement) {
        if (preg_match($pattern, $clean)) {
            $rulesMatched++;
            $clean = preg_replace($pattern, $replacement, $clean);
        }
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
            return [
                'draft' => 'BH-4 has completed a focused maintenance update to a supporting site service. '
                    . pw_dispatch_draft_diff_sentence($diffContext)
                    . ' It reduces avoidable friction behind the scenes while keeping the reader-facing experience steady.',
                'confidence' => pw_dispatch_draft_confidence($rulesMatched),
                'hash' => pw_dispatch_draft_hash($subject, $body, $tag, $diffContext),
            ];
        }
        return [
            'draft' => 'This update contains internal maintenance and reliability improvements. It helps keep the site stable and ready for future changes.',
            'confidence' => pw_dispatch_draft_confidence(0),
            'hash' => pw_dispatch_draft_hash($subject, $body, $tag, $diffContext),
        ];
    }

    // A stable hash chooses an alternate phrasing for each commit. This keeps
    // repeated categories from reading like boilerplate while ensuring that a
    // regenerate action does not make the same source commit drift randomly.
    $pickVariant = static function (array $variants, string $salt) use ($subject, $recentTranslations): string {
        $count = count($variants);
        $index = (int) sprintf('%u', crc32($subject . '|' . $salt)) % $count;
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
            $benefit .= ' ' . $pickVariant($options, 'context');
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
        if (preg_match($pattern, $clean, $matches)) {
            $rulesMatched++;
            $arguments = array_map(static function ($value): string {
                return rtrim(lcfirst(trim($value)), '.');
            }, array_slice($matches, 1));
            $object = $arguments[0];
            $draft = vsprintf($template, $arguments);
            break;
        }
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

    if ($draft === '') {
        $draft = sprintf($template, rtrim($object, '.'));
    }

    // Commit-type templates give recurring domains their own BH-4 vocabulary.
    // The title still supplies the object, while the domain determines the
    // reader-facing intent: security work reads differently from community,
    // database, content, interface, or performance work.
    $domain = pw_dispatch_draft_domain($contextSource . ' ' . $clean . ' ' . $bodyContext, $tag, $diffContext);
    $mode = pw_dispatch_draft_action_mode($clean);
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
            'addition' => ['BH-4 has expanded the reader-facing record for %s.', 'BH-4 has added new reader context around %s.'],
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
    if (isset($domainTemplates[$domain][$mode])) {
        $draft = sprintf($pickVariant($domainTemplates[$domain][$mode], 'domain-' . $domain . '-' . $mode), rtrim($object, '.'));
    }

    $diffSentence = pw_dispatch_draft_diff_sentence($diffContext);
    if ($diffSentence !== '') {
        $rulesMatched++;
    }

    return [
        'draft' => $draft . ($diffSentence !== '' ? ' ' . $diffSentence : '') . ' ' . $benefit,
        'confidence' => pw_dispatch_draft_confidence($rulesMatched),
        'hash' => pw_dispatch_draft_hash($subject, $body, $tag, $diffContext),
    ];
}

/**
 * Confidence is deliberately tied to explainable reader-facing rules, not to
 * whether the generated prose sounds plausible. It tells editors how much of
 * a commit was recognized by the local formatter before they approve it.
 */
function pw_dispatch_draft_confidence(int $rulesMatched): array
{
    if ($rulesMatched >= 2) {
        return [
            'level' => 'high',
            'label' => 'High confidence',
            'rules_matched' => $rulesMatched,
            'explanation' => $rulesMatched . ' independent rules matched the commit wording and context. Review for accuracy, then approve or edit as needed.',
        ];
    }
    if ($rulesMatched >= 1) {
        return [
            'level' => 'medium',
            'label' => 'Medium confidence',
            'rules_matched' => $rulesMatched,
            'explanation' => '1 rule matched the commit wording. Check the draft carefully before publishing.',
        ];
    }

    return [
        'level' => 'low',
        'label' => 'Low confidence',
        'rules_matched' => 0,
        'explanation' => '0 rules matched the commit wording. Read and edit this draft carefully before publishing.',
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
    return hash('sha256', "dispatch-draft-v13\n" . $subject . "\n" . $body . "\n" . $tag . "\n" . json_encode($diffContext));
}

function pw_dispatch_draft_options_for_dispatch(PDO $db, int $dispatchId): array
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
    return [
        'diff_context' => $contexts[$dispatchId] ?? [],
        'recent_translations' => $recentTranslations,
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

    $result = pw_dispatch_end_user_draft(
        $entry['subject'],
        (string)$entry['body'],
        $entry['tag'],
        pw_dispatch_draft_options_for_dispatch($db, $dispatchId)
    );
    if ($result['confidence']['level'] === 'high') {
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
            ];
        }
        return ['ok' => false, 'reason' => 'published'];
    }

    $stmt = $db->prepare(
        'INSERT INTO dispatch_translation_drafts (dispatch_id, sha, draft, source, draft_hash)
         VALUES (?, ?, ?, \'rule_based\', ?)
         ON DUPLICATE KEY UPDATE
           sha = VALUES(sha),
           draft = IF(draft_hash <> VALUES(draft_hash), VALUES(draft), draft),
           draft_hash = VALUES(draft_hash),
           source = VALUES(source)'
    );
    $stmt->execute([$dispatchId, $entry['sha'], $result['draft'], $result['hash']]);
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
    ];
}
