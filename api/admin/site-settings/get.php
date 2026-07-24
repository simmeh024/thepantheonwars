<?php
require_once __DIR__ . '/../../oauth.php';

pw_require_permission('site_settings.view');
pw_json(['ok' => true, 'oauth' => pw_oauth_settings(), 'maintenance' => pw_maintenance_settings_raw(), 'features' => pw_site_feature_settings()]);
