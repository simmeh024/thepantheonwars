<?php
require_once __DIR__ . '/missions-helpers.php';

pw_require_permission('missions.view');
pw_json(['ok' => true, 'watermark' => pw_missions_watermark_settings()]);
