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
$stmt = $db->prepare('SELECT id, subject, tag, category_confidence, category_source FROM dispatch_entries WHERE id = ?');
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

// Any explicit category save from this endpoint counts as a deliberate human
// review, even if the admin ends up choosing the same value the auto-scorer
// already had -- that's still a person having looked, which is exactly what
// clears a dispatch off the "needs review" queue. Confidence resets to 100
// (a human decision) and the previous auto/manual state is preserved in
// dispatch_category_overrides as a permanent evidence trail for future
// tuning of the scorer's keyword lists and weights.
$tag = $existing['tag'];
$categoryReviewed = false;
if (array_key_exists('tag', $input)) {
    $tag = trim((string)$input['tag']);
    if (!in_array($tag, pw_dispatch_valid_tags(), true)) {
        pw_error('Not a valid category.');
    }
    $categoryReviewed = true;
}

if ($categoryReviewed) {
    $db->prepare(
        'INSERT INTO dispatch_category_overrides (dispatch_id, previous_tag, previous_confidence, previous_source, new_tag, changed_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$dispatchId, $existing['tag'], $existing['category_confidence'], $existing['category_source'], $tag, (int)$adminUser['id']]);

    $stmt = $db->prepare('UPDATE dispatch_entries SET subject = ?, tag = ?, category_confidence = 100, category_source = ? WHERE id = ?');
    $stmt->execute([$subject, $tag, 'manual', $dispatchId]);
} else {
    $stmt = $db->prepare('UPDATE dispatch_entries SET subject = ?, tag = ? WHERE id = ?');
    $stmt->execute([$subject, $tag, $dispatchId]);
}

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
