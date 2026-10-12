<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

App\Auth::requireLogin();
verify_csrf();

$service = new App\Services\EvaluationService();
$classId = (int) post_value('class_id', 0);
$studentId = post_value('student_id', '');
$studentId = $studentId !== '' ? (int) $studentId : null;
$currentUserId = (int) App\Auth::id();
$isAdmin = App\Auth::isAdmin();

api_run(
    fn(): array => $service->spin($classId, $currentUserId, $isAdmin, $studentId, post_token('client_token')),
    'No se pudo completar el sorteo.'
);
