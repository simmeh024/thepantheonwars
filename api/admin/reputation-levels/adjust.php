<?php
require_once __DIR__ . '/../../helpers.php';
$admin = pw_require_permission('reputation.adjust');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$input = pw_input(); pw_require_csrf($input);
$memberId = (int)($input['member_id'] ?? 0); $points = (int)($input['points'] ?? 0); $reason = trim((string)($input['reason'] ?? ''));
if ($memberId <= 0 || $points === 0 || abs($points) > 1000 || $reason === '' || mb_strlen($reason) > 255) pw_error('Choose a member, a non-zero adjustment up to 1,000, and a reason.');
$db = pw_db(); $member = $db->prepare('SELECT id, display_name FROM users WHERE id = ?'); $member->execute([$memberId]); if (!$member->fetch()) pw_error('Member not found.', 404);
if ($points > 0) pw_award_reputation($db, $memberId, $points, 'staff_adjustment', ['label' => 'Staff reputation adjustment', 'actor_user_id' => $admin['id'], 'note' => $reason]);
else pw_remove_reputation($db, $memberId, abs($points), ['reward_key' => 'staff_adjustment', 'label' => 'Staff reputation adjustment', 'actor_user_id' => $admin['id'], 'note' => $reason]);
pw_log_admin_activity('reputation_adjusted', 'Adjusted member #' . $memberId . ' by ' . $points . ' reputation: ' . $reason, $admin);
pw_json(['ok' => true]);
