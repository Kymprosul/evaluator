<?php

declare(strict_types=1);

use App\Auth;
use App\Database;

define('APP_ROOT', dirname(__DIR__));

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Support/Env.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Repositories/ClassRepository.php';
require_once __DIR__ . '/Services/EvaluationService.php';
require_once __DIR__ . '/Services/ImportService.php';
require_once __DIR__ . '/Services/InviteService.php';
require_once __DIR__ . '/Services/ReportService.php';

App\Support\Env::load(APP_ROOT . '/.env');

$GLOBALS['app_config'] = require __DIR__ . '/config/app.php';

date_default_timezone_set((string) app_config('app.timezone', 'UTC'));

foreach ([storage_path('data'), storage_path('imports'), storage_path('exports')] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string) app_config('app.session_name', 'evaluator_session'));
    session_start([
        'cookie_secure' => is_https(),
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
        'use_only_cookies' => true,
    ]);
}

send_security_headers();

Database::migrate();
Auth::bootstrap();

if (isset($_GET['lang'])) {
    set_lang((string) $_GET['lang']);
}
