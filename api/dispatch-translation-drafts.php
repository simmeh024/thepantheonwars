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
            'draft' => 'This update contains internal maintenance and reliability improvements.',
            'hash' => hash('sha256', $subject . "\n" . $body . "\n" . $tag),
        ];
    }

    $object = lcfirst($clean);
    $actionTemplates = [
        '/^(?:add|create|introduce|include)\s+(.+)$/i' => 'A new update adds %s.',
        '/^(?:fix|resolve|repair)\s+(.+)$/i' => 'This update fixes %s.',
        '/^(?:restore)\s+(.+)$/i' => 'This update restores %s.',
        '/^(?:improve|enhance|refine|polish|streamline)\s+(.+)$/i' => 'This update improves %s.',
        '/^(?:optimi[sz]e|speed up)\s+(.+)$/i' => 'This update makes %s faster and more reliable.',
        '/^(?:update|refresh)\s+(.+)$/i' => 'This update refreshes %s.',
        '/^(?:remove|retire|delete)\s+(.+)$/i' => 'This update removes %s.',
        '/^(?:move|reorganize|reorganise)\s+(.+)$/i' => 'This update reorganizes %s.',
        '/^(?:secure|protect|harden)\s+(.+)$/i' => 'This update strengthens protection for %s.',
    ];
    foreach ($actionTemplates as $pattern => $template) {
        if (preg_match($pattern, $clean, $matches)) {
            $object = lcfirst(trim($matches[1]));
            return [
                'draft' => sprintf($template, rtrim($object, '.')),
                'hash' => hash('sha256', $subject . "\n" . $body . "\n" . $tag),
            ];
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

    return [
        'draft' => sprintf($template, rtrim($object, '.')),
        'hash' => hash('sha256', $subject . "\n" . $body . "\n" . $tag),
    ];
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
