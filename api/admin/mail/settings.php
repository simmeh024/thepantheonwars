<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../mail.php';

pw_require_permission('mail.view');
pw_json(['ok' => true, 'settings' => pw_mail_public_settings(), 'variables' => array_keys(pw_mail_variables())]);
