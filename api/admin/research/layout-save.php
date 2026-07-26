<?php
require_once __DIR__ . '/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('research.manage');
$input = pw_input();
pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$x = filter_var($input['canvas_x'] ?? null, FILTER_VALIDATE_INT);
$y = filter_var($input['canvas_y'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id < 1 || $x === false || $x < 0 || $x > PW_RESEARCH_BOARD_WIDTH - 196 || $y === false || $y < 0 || $y > PW_RESEARCH_BOARD_HEIGHT - 126) {
    pw_error('Choose a valid research node position.');
}
$db = pw_db();
pw_admin_research_require_ready($db);
$save = $db->prepare('UPDATE game_research_nodes SET canvas_x = ?, canvas_y = ? WHERE id = ?');
$save->execute([$x, $y, $id]);
if ($save->rowCount() !== 1) {
    /* MySQL reports zero affected rows when a drag ends where it started. That
     * is a valid no-op, not an ownership failure, so only report 404 if the
     * node is genuinely gone. */
    $exists = $db->prepare('SELECT 1 FROM game_research_nodes WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetch()) pw_error('That research node no longer exists.', 404);
}
pw_log_admin_activity('research_node_positioned', 'Moved a research protocol on the Research Management canvas.', $admin);
pw_json(['ok' => true, 'id' => $id, 'canvas_x' => $x, 'canvas_y' => $y]);
