<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

App\Auth::requireLogin();
verify_csrf();

$service = new App\Services\EvaluationService();
$classId = (int) post_value('class_id', 0);
$presentStudentIds = post_value('present_student_ids', []);
$attendanceDate = (string) post_value('attendance_date', '');
$currentUserId = (int) App\Auth::id();
$isAdmin = App\Auth::isAdmin();

if (!is_array($presentStudentIds)) {
    $presentStudentIds = [];
}

try {
    header('Cache-Control: no-store');
    json_response([
        'ok' => true,
        'data' => $service->saveAttendance($classId, $presentStudentIds, $attendanceDate, $currentUserId, $isAdmin),
    ]);
} catch (Throwable $exception) {
    header('Cache-Control: no-store');
    json_response([
        'ok' => false,
        'message' => $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo guardar la asistencia.',
    ], 422);
}
