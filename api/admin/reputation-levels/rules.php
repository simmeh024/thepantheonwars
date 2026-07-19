<?php
require_once __DIR__ . '/../../helpers.php';
$admin = pw_require_permission('reputation.edit');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$input = pw_input(); pw_require_csrf($input);
$rules = isset($input['rules']) && is_array($input['rules']) ? $input['rules'] : [];
try {
    $db = pw_db(); $known = array_column(pw_reputation_reward_catalog(), null, 'key');
    $update = $db->prepare('UPDATE reputation_reward_rules SET base_points = ?, is_enabled = ? WHERE `key` = ?');
    foreach ($rules as $rule) {
        $key = (string)($rule['key'] ?? ''); $points = (int)($rule['points'] ?? -1);
        if (!isset($known[$key]) || $points < 0 || $points > 100) pw_error('One of the reward rules is invalid.');
        $update->execute([$points, !empty($rule['enabled']) ? 1 : 0, $key]);
    }
    pw_log_admin_activity('reputation_rules_updated', 'Updated reputation reward rules.', $admin);
    pw_json(['ok' => true, 'rewards' => pw_reputation_reward_catalog()]);
} catch (Throwable $e) { pw_error('Reward rules require the reputation expansion migration.', 503); }
