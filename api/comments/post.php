<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$topicId = isset($input['topic_id']) ? (int)$input['topic_id'] : 0;
if ($topicId <= 0) {
    pw_error('Missing topic id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, user_id, is_locked FROM topics WHERE id = ? AND is_deleted = 0');
$stmt->execute([$topicId]);
$topicRow = $stmt->fetch();
if (!$topicRow) {
    pw_error('That topic no longer exists.', 404);
}
if ((int)$topicRow['is_locked'] === 1) {
    pw_error('This topic is locked. A moderator must unlock it before new replies can be posted.', 403);
}

// Note: anyone logged in may reply inside an existing topic, including in
// Announcements -- only *starting* a new Announcements topic is staff-only
// (enforced in api/topics/create.php).

$body = isset($input['body']) ? trim($input['body']) : '';
if ($body === '') {
    pw_error('Your message is empty.');
}
if (function_exists('mb_strlen') ? mb_strlen($body) > 3500 : strlen($body) > 3500) {
    pw_error('That message is too long (3500 characters max).');
}

$parentId = null;
$depth = 0;
if (!empty($input['parent_id'])) {
    $parentId = (int)$input['parent_id'];
    $stmt = $db->prepare('SELECT id, depth FROM comments WHERE id = ? AND topic_id = ? AND is_deleted = 0');
    $stmt->execute([$parentId, $topicId]);
    $parent = $stmt->fetch();
    if (!$parent) {
        pw_error('The message you are replying to no longer exists.');
    }
    $depth = (int)$parent['depth'] + 1;
    if ($depth > 2) {
        pw_error('Replies can only go two levels deep.');
    }
}

// Quote linking: the client-side Quote button (community.html) already
// pastes a [quote=...] block into the reply body -- these optional fields
// just tie that back to whatever it quoted, so a notification can be sent
// to the quoted author. quoted_kind is 'comment' or 'topic': a quote of
// the topic's *original* post has no comments row to link (that post
// lives in the topics table, not comments), so comments.quoted_comment_id
// stays null for that case, but the topic's author is still notified
// directly via topics.user_id. Both cases are validated against the
// current topic so a stray/forged id from elsewhere can't be attached or
// notified.
$quotedCommentId = null;
$quoteNotifyUserId = null;
$quotedKind = isset($input['quoted_kind']) ? $input['quoted_kind'] : null;
if ($quotedKind === 'comment' && !empty($input['quoted_target_id'])) {
    $stmt = $db->prepare('SELECT id, user_id FROM comments WHERE id = ? AND topic_id = ? AND is_deleted = 0');
    $stmt->execute([(int)$input['quoted_target_id'], $topicId]);
    $quotedComment = $stmt->fetch();
    if ($quotedComment) {
        $quotedCommentId = (int)$quotedComment['id'];
        $quoteNotifyUserId = (int)$quotedComment['user_id'];
    }
} elseif ($quotedKind === 'topic' && !empty($input['quoted_target_id'])) {
    if ((int)$input['quoted_target_id'] === $topicId) {
        $quoteNotifyUserId = (int)$topicRow['user_id'];
    }
}

$stmt = $db->prepare('INSERT INTO comments (user_id, topic_id, parent_id, quoted_comment_id, depth, body) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute([$user['id'], $topicId, $parentId, $quotedCommentId, $depth, $body]);
$commentId = (int)$db->lastInsertId();

// Notify everyone watching this thread (topic creator and past repliers
// are auto-subscribed elsewhere) about the new reply, then auto-subscribe
// the replier too so they hear about whatever comes after their own post.
// Both best-effort: never block posting the reply itself.
try {
    $watcherStmt = $db->prepare('SELECT user_id FROM topic_subscriptions WHERE topic_id = ?');
    $watcherStmt->execute([$topicId]);
    foreach ($watcherStmt->fetchAll() as $watcherRow) {
        pw_notify((int)$watcherRow['user_id'], 'topic_reply', $user['id'], $topicId, $commentId, null, $body);
    }
    $db->prepare('INSERT IGNORE INTO topic_subscriptions (user_id, topic_id) VALUES (?, ?)')->execute([$user['id'], $topicId]);
} catch (PDOException $e) {
    // migration_forum_features_batch.sql may be run after code deployment.
}

// +1 base reputation for replying. The active event multiplier is resolved
// server-side so a member cannot influence the amount from the browser.
try {
    pw_award_reputation($db, (int)$user['id'], 1, 'comment_posted', ['source_type' => 'comment', 'source_id' => $commentId]);
} catch (PDOException $e) {
    // migration_reputation.sql may be run after code deployment.
}

if ($quoteNotifyUserId !== null) {
    pw_notify($quoteNotifyUserId, 'quote', $user['id'], $topicId, $commentId, null, $body);
    pw_log_admin_activity('content_quoted', 'Quoted a post in topic #' . $topicId . ' (reply #' . $commentId . ').', $user);
}

$mentionedUserIds = pw_extract_mentions($body, $user['id']);
foreach ($mentionedUserIds as $mentionedUserId) {
    pw_notify($mentionedUserId, 'mention', $user['id'], $topicId, $commentId, null, $body);
}
if (!empty($mentionedUserIds)) {
    pw_log_admin_activity('user_mentioned', count($mentionedUserIds) . ' mention(s) in reply #' . $commentId . ' (topic #' . $topicId . ').', $user);
}

pw_json(['ok' => true, 'id' => $commentId]);
