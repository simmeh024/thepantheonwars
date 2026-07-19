<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$board = isset($input['board']) ? trim($input['board']) : '';
if (!preg_match('/^[a-z0-9\-]{1,50}$/', $board)) {
    pw_error('Unknown board.');
}

$boardRow = pw_forum_board_by_slug($board);
if (!$boardRow || !pw_can_see_board($user, $boardRow)) {
    pw_error('Unknown board.');
}

if ($board === 'announcements' && !pw_has_permission($user, 'community.post_announcements')) {
    pw_error('Only the author and moderators can start new topics in Announcements.', 403);
}

$title = isset($input['title']) ? trim($input['title']) : '';
$body = isset($input['body']) ? trim($input['body']) : '';

$titleLen = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
$bodyLen = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);

if ($title === '') {
    pw_error('Give your topic a title.');
}
if ($titleLen > 200) {
    pw_error('That title is too long (200 characters max).');
}
if ($body === '') {
    pw_error('Your message is empty.');
}
if ($bodyLen > 3500) {
    pw_error('That message is too long (3500 characters max).');
}

// Optional poll, only settable at creation time. 2-6 non-empty options;
// blank/whitespace-only entries are dropped before the count check so a
// stray empty input field from the builder UI can't slip through.
$pollQuestion = null;
$pollOptions = [];
if (!empty($input['poll']) && is_array($input['poll'])) {
    $pollQuestion = isset($input['poll']['question']) ? trim((string)$input['poll']['question']) : '';
    $rawOptions = isset($input['poll']['options']) && is_array($input['poll']['options']) ? $input['poll']['options'] : [];
    foreach ($rawOptions as $rawOption) {
        $option = trim((string)$rawOption);
        if ($option !== '') {
            $pollOptions[] = $option;
        }
    }
    if ($pollQuestion === '') {
        pw_error('Give your poll a question, or remove it.');
    }
    if (mb_strlen($pollQuestion) > 300) {
        pw_error('The poll question is too long (300 characters max).');
    }
    if (count($pollOptions) < 2 || count($pollOptions) > 6) {
        pw_error('A poll needs between 2 and 6 options.');
    }
    foreach ($pollOptions as $option) {
        if (mb_strlen($option) > 200) {
            pw_error('Each poll option is too long (200 characters max).');
        }
    }
}

$db = pw_db();
try {
    $db->beginTransaction();
    $stmt = $db->prepare('INSERT INTO topics (board, user_id, title, body) VALUES (?, ?, ?, ?)');
    $stmt->execute([$board, $user['id'], $title, $body]);
    $topicId = (int)$db->lastInsertId();

    if ($pollQuestion !== null) {
        $pollStmt = $db->prepare('INSERT INTO topic_polls (topic_id, question) VALUES (?, ?)');
        $pollStmt->execute([$topicId, $pollQuestion]);
        $pollId = (int)$db->lastInsertId();
        $optionStmt = $db->prepare('INSERT INTO topic_poll_options (poll_id, label, sort_order) VALUES (?, ?, ?)');
        foreach ($pollOptions as $index => $option) {
            $optionStmt->execute([$pollId, $option, $index]);
        }
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

// Auto-watch your own topic so you're notified of replies -- best-effort,
// never blocks topic creation itself.
try {
    $db->prepare('INSERT IGNORE INTO topic_subscriptions (user_id, topic_id) VALUES (?, ?)')->execute([$user['id'], $topicId]);
} catch (PDOException $e) {
    // migration_forum_features_batch.sql may be run after code deployment.
}

// +1 base reputation for starting a topic. The centralized helper applies an
// active event multiplier server-side; failures never block posting.
try {
    pw_award_reputation($db, (int)$user['id'], 1);
} catch (PDOException $e) {
    // migration_reputation.sql may be run after code deployment.
}

$mentionedUserIds = pw_extract_mentions($body, $user['id']);
foreach ($mentionedUserIds as $mentionedUserId) {
    pw_notify($mentionedUserId, 'mention', $user['id'], $topicId, null, null, $title);
}
if (!empty($mentionedUserIds)) {
    pw_log_admin_activity('user_mentioned', count($mentionedUserIds) . ' mention(s) in topic #' . $topicId . ' ("' . $title . '").', $user);
}

pw_json(['ok' => true, 'id' => $topicId]);
