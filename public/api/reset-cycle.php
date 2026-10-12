<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

App\Auth::requireLogin();
verify_csrf();

$service = new App\Services\EvaluationService();
$classId = (int) post_value('class_id', 0);
$currentUserId = (int) App\Auth::id();
$isAdmin = App\Auth::isAdmin();

api_run(
    fn(): array => $service->resetCycle($classId, $currentUserId, $isAdmin, post_token('client_token')),
    'No se pudo crear una nueva ronda.'
);
