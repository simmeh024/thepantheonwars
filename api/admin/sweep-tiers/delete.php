<?php
require_once __DIR__ . '/../../missions/sweep-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('sweep_tiers.manage');
$input = pw_input();
pw_require_csrf($input);
$db = pw_db();
if (!pw_sweep_ready($db)) {
    pw_error('Run sql/migration_salvage_sweep.sql before editing Sweep Tiers.', 503);
}

$rank = filter_var($input['rank_number'] ?? null, FILTER_VALIDATE_INT);
if ($rank === false || $rank < 1) pw_error('Choose a sweep tier to remove.');

/* Runs are deliberately not touched. game_player_sweep_runs stores the whole
 * board it was opened with -- size, hazards, seed, manifest -- so a sweep in
 * play survives its sector being retired, and a banked one keeps its history.
 * Retiring a rank simply means no new board opens there. */
$delete = $db->prepare('DELETE FROM game_sweep_tiers WHERE rank_number = ?');
$delete->execute([$rank]);
if ($delete->rowCount() < 1) pw_error('That sweep tier no longer exists.', 404);

pw_log_admin_activity('sweep_tier_deleted', 'Removed the Salvage Sweep sector for rank ' . $rank . '.', $admin);
pw_json(['ok' => true]);
