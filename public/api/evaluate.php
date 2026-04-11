<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

App\Auth::requireLogin();
verify_csrf();

$service = new App\Services\EvaluationService();
$classId = (int) post_value('class_id', 0);
$evaluationId = (int) post_value('evaluation_id', 0);
$score = (string) post_value('score', '');
$currentUserId = (int) App\Auth::id();
$isAdmin = App\Auth::isAdmin();

try {
    json_response([
        'ok' => true,
        'data' => $service->evaluate($classId, $evaluationId, $score, $currentUserId, $isAdmin),
    ]);
} catch (Throwable $exception) {
    json_response([
        'ok' => false,
        'message' => $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo guardar la evaluación.',
    ], 422);
}
