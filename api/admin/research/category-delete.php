<?php
require_once __DIR__ . '/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('research.manage');
$input = pw_input();
pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) pw_error('Choose a valid research category.');
$db = pw_db();
pw_admin_research_require_ready($db);

try {
    $db->beginTransaction();
    $category = $db->prepare('SELECT id, name FROM game_research_categories WHERE id = ? FOR UPDATE');
    $category->execute([$id]);
    $row = $category->fetch();
    if (!$row) throw new RuntimeException('Research category not found.');
    $delete = $db->prepare('DELETE FROM game_research_categories WHERE id = ?');
    $delete->execute([$id]);
    $db->commit();
    pw_log_admin_activity('research_category_deleted', 'Deleted research category "' . $row['name'] . '". Its protocols are now uncategorised.', $admin);
    pw_json(['ok' => true]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not delete this research category.', 409);
}
