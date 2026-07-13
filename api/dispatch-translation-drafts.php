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
    $clean = preg_replace('/\b(?:api|css|javascript|js|php|sql|mysql|mariadb|cron|cache|caching|query|queries|endpoint|webhook)\b/i', 'site', $clean);
    $clean = preg_replace('/\b(?:ui\/ux|ui)\b/i', 'interface', $clean);
    $clean = preg_replace('/\b(?:admin console)\b/i', 'Admin Console', $clean);
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

    $clean = ucfirst($clean);
    $templates = [
        'feature' => 'A new update adds %s.',
        'improvement' => 'This update improves %s.',
        'fix' => 'This update resolves an issue affecting %s.',
        'performance' => 'This update improves the speed and reliability of %s.',
        'ui_ux' => 'This update refines the interface around %s.',
        'lore' => 'This update expands the worldbuilding around %s.',
        'infrastructure' => 'This update strengthens the reliability of %s.',
        'refactor' => 'This update improves the underlying structure supporting %s.',
        'experimental' => 'This update introduces an experimental improvement for %s.',
    ];
    $template = isset($templates[$tag]) ? $templates[$tag] : 'This update improves %s.';

    return [
        'draft' => sprintf($template, $clean),
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
