<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/community-pulse-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pw_error('Method not allowed.', 405);
}

pw_require_permission('dashboards.view_home');
pw_json(pw_get_community_pulse(pw_db()));
