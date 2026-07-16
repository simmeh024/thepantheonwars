<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../mail.php';

pw_require_permission('mail.view');
try {
    $rows = pw_db()->query('SELECT template_key, label, description, subject, html_body, text_body, is_enabled, updated_at FROM mail_templates ORDER BY id')->fetchAll();
} catch (Throwable $e) {
    pw_error('Mail templates are not configured yet. Run the mail-system migration first.', 409);
}
pw_json(['ok' => true, 'templates' => $rows, 'variables' => array_keys(pw_mail_variables())]);
