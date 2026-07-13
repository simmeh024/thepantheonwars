<?php
/**
 * Local, deterministic copy formatter for end-user Dispatch drafts. It does
 * not call an external service and never publishes anything: callers save the
 * result in dispatch_translation_drafts for an editor to approve or edit.
 */
function pw_dispatch_end_user_draft(string $subject, string $body, string $tag): array
{
    $clean = trim($subject);
    $clean = preg_replace('/^(?:feat(?:ure)?|fix|perf(?:ormance)?|refactor|chore|docs?|style|test)(?:\([^)]*\))?!?:\s*/i', '', $clean);
    $clean = preg_replace('/\s*\(?#[0-9]+\)?\s*$/', '', $clean);
    $clean = str_replace('_', ' ', $clean);
    $clean = preg_replace('/([a-z])\-([a-z])/i', '$1 $2', $clean);
    $clean = preg_replace('/\s*\+\s*/', ' and ', $clean);

    // These are editorial substitutions, not opaque technical word removal.
    // They retain the commit's meaning while speaking in the language readers
    // encounter on the site. The most specific replacements come first.
    $replacements = [
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
        $clean = preg_replace($pattern, $replacement, $clean);
    }
    $clean = preg_replace('/\s+/', ' ', trim($clean));
    $clean = trim($clean, " .:-");

    // Do not expose a file path, a hash-like token, or an empty technical
    // subject to readers. The neutral wording deliberately avoids claims
    // about a feature when the source message cannot be safely rephrased.
    $unsafe = $clean === '' || preg_match('/(?:\b[0-9a-f]{7,40}\b|[\\\\\/]|\.php\b|\.js\b|\.css\b)/i', $clean);
    if ($unsafe) {
        return [
            'draft' => 'This update contains internal maintenance and reliability improvements. It helps keep the site stable and ready for future changes.',
            'hash' => pw_dispatch_draft_hash($subject, $body, $tag),
        ];
    }

    $benefits = [
        'feature' => 'It gives visitors and community members a new, clearly focused part of the site to use. The addition is designed to fit naturally into the existing Pantheon Wars experience.',
        'improvement' => 'It makes the affected area clearer, more consistent, and easier to use. The goal is a smoother experience without changing the familiar flow of the site.',
        'fix' => 'It helps the affected area behave consistently for visitors and staff. This makes everyday use more dependable while preserving the intended experience.',
        'performance' => 'It reduces unnecessary work behind the scenes for a smoother experience. This helps the affected pages stay responsive as more content and visitors are added.',
        'ui_ux' => 'It makes the interface easier to read, navigate, and use. It also keeps the visual language consistent across the site and Admin Console.',
        'lore' => 'It gives readers more detail and context to explore in the world of Pantheon Wars. The update is intended to deepen the setting without changing established story information.',
        'infrastructure' => 'It helps keep the site reliable during everyday use and future updates. The work also gives later improvements a steadier foundation to build on.',
        'refactor' => 'No visible feature is changed, but it makes future improvements safer and easier to deliver. The underlying structure is kept clearer so the experience can evolve reliably.',
        'experimental' => 'It is an early improvement that can be refined after it has been reviewed in use. The change remains focused and can be adjusted as the site develops.',
    ];
    $benefit = $benefits[$tag] ?? 'It helps keep the site clear, reliable, and ready for future updates.';
    $bh4Openers = [
        'feature' => 'BH-4 briefing: ',
        'improvement' => 'BH-4 update: ',
        'fix' => 'BH-4 correction: ',
        'performance' => 'BH-4 efficiency report: ',
        'ui_ux' => 'BH-4 interface note: ',
        'lore' => 'BH-4 archive note: ',
        'infrastructure' => 'BH-4 systems note: ',
        'refactor' => 'BH-4 maintenance note: ',
        'experimental' => 'BH-4 field note: ',
    ];
    $bh4Opener = $bh4Openers[$tag] ?? 'BH-4 briefing: ';
    $object = lcfirst($clean);
    $draft = '';
    $actionTemplates = [
        '/^add\s+(.+)\s+and\s+fix\s+(.+)$/i' => 'This update adds %s and corrects %s.',
        '/^(?:add|create|introduce|include)\s+(.+)$/i' => 'A new update adds %s.',
        '/^(?:fix|resolve|repair)\s+(.+)$/i' => 'This update fixes %s.',
        '/^(?:restore)\s+(.+)$/i' => 'This update restores %s.',
        '/^(?:improve|enhance|refine|polish|streamline)\s+(.+)$/i' => 'This update improves %s.',
        '/^(?:expand)\s+(.+)$/i' => 'This update adds more detail to %s.',
        '/^(?:keep|show)\s+(.+)$/i' => 'This update keeps %s clear and easy to read.',
        '/^(?:throttle|reduce|defer)\s+(.+)$/i' => 'This update reduces unnecessary %s.',
        '/^(?:prevent)\s+(.+)$/i' => 'This update helps prevent %s.',
        '/^(?:reserve)\s+(.+)$/i' => 'This update reserves clear space for %s.',
        '/^(?:use|switch)\s+(.+)$/i' => 'This update standardizes %s.',
        '/^(?:load|deliver)\s+(.+)$/i' => 'This update delivers %s more efficiently.',
        '/^(?:optimi[sz]e|speed up)\s+(.+)$/i' => 'This update makes %s faster and more reliable.',
        '/^(?:update|refresh)\s+(.+)$/i' => 'This update refreshes %s.',
        '/^(?:remove|retire|delete)\s+(.+)$/i' => 'This update removes %s.',
        '/^(?:move|reorganize|reorganise)\s+(.+)$/i' => 'This update reorganizes %s.',
        '/^(?:secure|protect|harden)\s+(.+)$/i' => 'This update strengthens protection for %s.',
    ];
    foreach ($actionTemplates as $pattern => $template) {
        if (preg_match($pattern, $clean, $matches)) {
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

    return [
        'draft' => $bh4Opener . $draft . ' ' . $benefit,
        'hash' => pw_dispatch_draft_hash($subject, $body, $tag),
    ];
}

// Bump the format version whenever wording rules change. Regenerate Draft then
// refreshes old unapproved drafts even when their source commit is unchanged.
function pw_dispatch_draft_hash(string $subject, string $body, string $tag): string
{
    return hash('sha256', "dispatch-draft-v4\n" . $subject . "\n" . $body . "\n" . $tag);
}

function pw_create_dispatch_translation_draft(PDO $db, int $dispatchId): bool
{
    $entryStmt = $db->prepare('SELECT id, sha, subject, body, tag FROM dispatch_entries WHERE id = ?');
    $entryStmt->execute([$dispatchId]);
    $entry = $entryStmt->fetch();
    if (!$entry) {
        return false;
    }

    $approvedStmt = $db->prepare('SELECT 1 FROM dispatch_translations WHERE dispatch_id = ?');
    $approvedStmt->execute([$dispatchId]);
    if ($approvedStmt->fetch()) {
        return false;
    }

    $result = pw_dispatch_end_user_draft($entry['subject'], (string)$entry['body'], $entry['tag']);
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
    return true;
}
