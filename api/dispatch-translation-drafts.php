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

    // These are editorial substitutions, not opaque technical word removal.
    // They retain the commit's meaning while speaking in the language readers
    // encounter on the site. The most specific replacements come first.
    $replacements = [
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
    $object = lcfirst($clean);
    $draft = '';
    $actionTemplates = [
        '/^(?:add|create|introduce|include)\s+(.+)$/i' => 'A new update adds %s.',
        '/^(?:fix|resolve|repair)\s+(.+)$/i' => 'This update fixes %s.',
        '/^(?:restore)\s+(.+)$/i' => 'This update restores %s.',
        '/^(?:improve|enhance|refine|polish|streamline)\s+(.+)$/i' => 'This update improves %s.',
        '/^(?:expand)\s+(.+)$/i' => 'This update adds more detail to %s.',
        '/^(?:keep)\s+(.+)$/i' => 'This update keeps %s clear and easy to read.',
        '/^(?:throttle|reduce)\s+(.+)$/i' => 'This update reduces unnecessary %s.',
        '/^(?:load|deliver)\s+(.+)$/i' => 'This update delivers %s more efficiently.',
        '/^(?:optimi[sz]e|speed up)\s+(.+)$/i' => 'This update makes %s faster and more reliable.',
        '/^(?:update|refresh)\s+(.+)$/i' => 'This update refreshes %s.',
        '/^(?:remove|retire|delete)\s+(.+)$/i' => 'This update removes %s.',
        '/^(?:move|reorganize|reorganise)\s+(.+)$/i' => 'This update reorganizes %s.',
        '/^(?:secure|protect|harden)\s+(.+)$/i' => 'This update strengthens protection for %s.',
    ];
    foreach ($actionTemplates as $pattern => $template) {
        if (preg_match($pattern, $clean, $matches)) {
            $object = lcfirst(trim($matches[1]));
            $draft = sprintf($template, rtrim($object, '.'));
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
        'draft' => $draft . ' ' . $benefit,
        'hash' => pw_dispatch_draft_hash($subject, $body, $tag),
    ];
}

// Bump the format version whenever wording rules change. Regenerate Draft then
// refreshes old unapproved drafts even when their source commit is unchanged.
function pw_dispatch_draft_hash(string $subject, string $body, string $tag): string
{
    return hash('sha256', "dispatch-draft-v3\n" . $subject . "\n" . $body . "\n" . $tag);
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
