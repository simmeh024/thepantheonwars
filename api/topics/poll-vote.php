<?php
require_once __DIR__ . '/../helpers.php';

// Single-choice, one vote per member per poll. Re-voting replaces the
// previous choice (INSERT ... ON DUPLICATE KEY UPDATE against the
// (poll_id, user_id) unique key) rather than rejecting a second vote --
// members expect to be able to change their mind before a poll closes,
// and this project has no poll-closing mechanism in this version anyway.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$pollId = isset($input['poll_id']) ? (int)$input['poll_id'] : 0;
$optionId = isset($input['option_id']) ? (int)$input['option_id'] : 0;
if ($pollId <= 0 || $optionId <= 0) {
    pw_error('Missing poll or option id.');
}

$db = pw_db();

// The option must genuinely belong to the poll being voted on -- otherwise
// a forged option_id from a different poll could attach a vote count to
// the wrong poll's option list.
$stmt = $db->prepare(
    'SELECT o.id FROM topic_poll_options o
     JOIN topic_polls p ON p.id = o.poll_id
     JOIN topics t ON t.id = p.topic_id AND t.is_deleted = 0
     WHERE o.poll_id = ? AND o.id = ?'
);
$stmt->execute([$pollId, $optionId]);
if (!$stmt->fetch()) {
    pw_error('That poll option no longer exists.', 404);
}

$stmt = $db->prepare(
    'INSERT INTO topic_poll_votes (poll_id, option_id, user_id) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE option_id = VALUES(option_id)'
);
$stmt->execute([$pollId, $optionId, (int)$user['id']]);

$resultsStmt = $db->prepare(
    'SELECT id, label, (SELECT COUNT(*) FROM topic_poll_votes v WHERE v.option_id = o.id) AS vote_count
     FROM topic_poll_options o WHERE o.poll_id = ? ORDER BY o.sort_order ASC, o.id ASC'
);
$resultsStmt->execute([$pollId]);
$options = $resultsStmt->fetchAll();

pw_json([
    'ok' => true,
    'options' => array_map(function ($o) {
        return ['id' => (int)$o['id'], 'label' => $o['label'], 'vote_count' => (int)$o['vote_count']];
    }, $options),
    'total_votes' => array_sum(array_map(function ($o) { return (int)$o['vote_count']; }, $options)),
    'my_vote' => $optionId,
]);
