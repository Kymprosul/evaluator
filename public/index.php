<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (App\Auth::userCount() === 0) {
    redirect_to(app_url('setup.php'));
}

if (App\Auth::check()) {
    redirect_to(app_url('dashboard.php'));
}

redirect_to(app_url('login.php'));
