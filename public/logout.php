<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

App\Auth::logout();
session_name((string) app_config('app.session_name', 'evaluator_session'));
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);
flash('success', 'Sesión cerrada.');

redirect_to(app_url('login.php'));
