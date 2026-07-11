<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../dispatch-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatches.edit');

$input = pw_input();
pw_require_csrf($input);

$dispatchId = isset($input['dispatch_id']) ? (int)$input['dispatch_id'] : 0;
if ($dispatchId <= 0) {
    pw_error('Missing dispatch id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, subject, tag FROM dispatch_entries WHERE id = ?');
$stmt->execute([$dispatchId]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('That dispatch no longer exists.', 404);
}

$subject = $existing['subject'];
if (array_key_exists('subject', $input)) {
    $subject = trim((string)$input['subject']);
    if ($subject === '') {
        pw_error('Title can\'t be empty.');
    }
    if (strlen($subject) > 500) {
        pw_error('Title is too long (500 characters max).');
    }
}

$tag = $existing['tag'];
if (array_key_exists('tag', $input)) {
    $tag = trim((string)$input['tag']);
    if (!in_array($tag, pw_dispatch_valid_tags(), true)) {
        pw_error('Not a valid category.');
    }
}

$stmt = $db->prepare('UPDATE dispatch_entries SET subject = ?, tag = ? WHERE id = ?');
$stmt->execute([$subject, $tag, $dispatchId]);

if ($tag !== $existing['tag']) {
    $tagLabels = [
        'feature' => 'Feature', 'improvement' => 'Improvement', 'fix' => 'Fix',
        'performance' => 'Performance', 'ui_ux' => 'UI / UX', 'lore' => 'Lore',
        'infrastructure' => 'Infrastructure', 'refactor' => 'Refactor', 'experimental' => 'Experimental',
    ];
    $fromLabel = isset($tagLabels[$existing['tag']]) ? $tagLabels[$existing['tag']] : $existing['tag'];
    $toLabel = isset($tagLabels[$tag]) ? $tagLabels[$tag] : $tag;
    pw_log_admin_activity(
        'category_edited',
        'Changed category for "' . $subject . '" from ' . $fromLabel . ' to ' . $toLabel . '.',
        $adminUser
    );
} elseif ($subject !== $existing['subject']) {
    // Title-only edit (no category change) used to leave no audit trail at
    // all, since the log call above only fired on a tag change.
    pw_log_admin_activity(
        'dispatch_title_edited',
        'Changed dispatch title from "' . $existing['subject'] . '" to "' . $subject . '".',
        $adminUser
    );
}

pw_json(['ok' => true, 'subject' => $subject, 'tag' => $tag]);
