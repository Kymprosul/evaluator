<?php

declare(strict_types=1);

// Read-only: returns the class state plus the current CSRF token, so the browser can
// reconcile after a rejection and recover from a token that changed after a new login.

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

App\Auth::requireLogin();

$service = new App\Services\EvaluationService();
$classId = (int) get_value('class_id', 0);
$currentUserId = (int) App\Auth::id();
$isAdmin = App\Auth::isAdmin();

api_run(
    fn(): array => [
        'state' => $service->state($classId, $currentUserId, $isAdmin),
        'csrf_token' => csrf_token(),
    ],
    'No se pudo leer el estado de la clase.'
);
