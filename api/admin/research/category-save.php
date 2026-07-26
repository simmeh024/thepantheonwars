<?php
require_once __DIR__ . '/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('research.manage');
$input = pw_input();
pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$id = $id === false ? null : $id;
$db = pw_db();
pw_admin_research_require_ready($db);

try {
    $db->beginTransaction();
    if ($id !== null) {
        $existing = $db->prepare('SELECT id FROM game_research_categories WHERE id = ? FOR UPDATE');
        $existing->execute([$id]);
        if (!$existing->fetch()) throw new RuntimeException('Research category not found.');
    }
    $data = pw_admin_research_category_input($input);
    if ($id === null) {
        $insert = $db->prepare('INSERT INTO game_research_categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)');
        $insert->execute(array_values($data));
        $id = (int)$db->lastInsertId();
        $action = 'research_category_created';
        $verb = 'Created';
    } else {
        $update = $db->prepare('UPDATE game_research_categories SET name = ?, slug = ?, description = ?, sort_order = ? WHERE id = ?');
        $update->execute(array_merge(array_values($data), [$id]));
        $action = 'research_category_updated';
        $verb = 'Updated';
    }
    $db->commit();
    pw_log_admin_activity($action, $verb . ' research category "' . $data['name'] . '".', $admin);
    pw_json(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not save this research category. Check that the slug is unique.', 409);
}
