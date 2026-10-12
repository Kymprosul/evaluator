<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Repositories\ClassRepository;
use PDO;
use PDOException;
use RuntimeException;

final class EvaluationService
{
    public function __construct(
        private readonly ClassRepository $classes = new ClassRepository()
    ) {
    }

    public function state(int $classId, int $viewerUserId, bool $isAdmin): array
    {
        $classroom = $this->accessibleClassroom($classId, $viewerUserId, $isAdmin);

        $cycle = $this->currentCycle($classId);
        $remainingStudents = $this->remainingStudents($classId, (int) $cycle['id']);
        $pendingEvaluation = $this->pendingEvaluation($classId, (int) $cycle['id']);
        $evaluatedCount = $this->cycleEvaluatedCount((int) $cycle['id']);

        return [
            'class' => [
                'id' => (int) $classroom['id'],
                'code' => $classroom['code'],
                'name' => $classroom['name'],
                'term' => $classroom['term'],
                'year' => (int) $classroom['year'],
                'owner_username' => $classroom['owner_username'] ?? null,
                'students_count' => (int) $classroom['students_count'],
            ],
            'cycle' => [
                'id' => (int) $cycle['id'],
                'number' => (int) $cycle['cycle_number'],
                'status' => $cycle['status'],
                'started_at' => $cycle['started_at'],
                'finished_at' => $cycle['finished_at'],
            ],
            'remaining_students' => array_map([$this, 'formatStudent'], $remainingStudents),
            'pending_evaluation' => $pendingEvaluation === null ? null : $this->formatEvaluation($pendingEvaluation),
            'recent_evaluations' => $this->recentEvaluations($classId),
            'attendance' => $this->attendanceState($classId),
            'stats' => [
                'total_students' => (int) $classroom['students_count'],
                'remaining_students' => count($remainingStudents),
                'evaluated_students' => $evaluatedCount,
                'can_spin' => $cycle['status'] === 'open' && $pendingEvaluation === null && count($remainingStudents) > 0,
                'can_reset' => (int) $classroom['students_count'] > 0 && $pendingEvaluation === null,
            ],
        ];
    }

    /**
     * Replaces the attendance of one day. $attendanceDate may be a past day (offline backups).
     * With $clientTimestamp set, the save is skipped when the server already holds a newer version.
     */
    public function saveAttendance(
        int $classId,
        array $presentStudentIds,
        ?string $attendanceDate,
        int $userId,
        bool $isAdmin,
        ?int $clientTimestamp = null
    ): array {
        $classroom = $this->accessibleClassroom($classId, $userId, $isAdmin);
        $attendanceDate = $this->normalizeAttendanceDate($attendanceDate);
        $students = $this->classes->studentsForClass($classId);
        $allowedStudentIds = array_values(array_map(
            static fn(array $student): int => (int) $student['id'],
            array_filter($students, static fn(array $student): bool => empty($student['is_vip']))
        ));

        if ($clientTimestamp !== null) {
            $latest = $this->latestAttendanceMark($classId, $attendanceDate);
            if ($latest !== null && strtotime($latest) > intdiv($clientTimestamp, 1000)) {
                return [
                    'message' => 'El servidor ya tiene una versión más reciente de esa asistencia.',
                    'skipped' => true,
                    'date' => $attendanceDate,
                    'state' => $this->state($classId, $userId, $isAdmin),
                ];
            }
        }

        $normalized = array_values(array_unique(array_map(static fn(mixed $id): int => (int) $id, $presentStudentIds)));
        $filtered = array_values(array_filter(
            $normalized,
            static fn(int $id): bool => in_array($id, $allowedStudentIds, true)
        ));

        Database::transaction(function () use ($classId, $attendanceDate, $allowedStudentIds, $filtered, $userId): void {
            $pdo = Database::connection();
            $delete = $pdo->prepare(
                'DELETE FROM attendance_records WHERE class_id = :class_id AND attendance_date = :attendance_date'
            );
            $delete->execute([
                'class_id' => $classId,
                'attendance_date' => $attendanceDate,
            ]);

            $insert = $pdo->prepare(
                'INSERT INTO attendance_records (class_id, student_id, attendance_date, attendance_score, marked_by, marked_at)
                 VALUES (:class_id, :student_id, :attendance_date, :attendance_score, :marked_by, :marked_at)'
            );

            foreach ($allowedStudentIds as $studentId) {
                $insert->execute([
                    'class_id' => $classId,
                    'student_id' => $studentId,
                    'attendance_date' => $attendanceDate,
                    'attendance_score' => in_array($studentId, $filtered, true) ? 2 : 0,
                    'marked_by' => $userId,
                    'marked_at' => date('Y-m-d H:i:s'),
                ]);
            }
        });

        return [
            'message' => 'Asistencia guardada.',
            'date' => $attendanceDate,
            'present_count' => count($filtered),
            'state' => $this->state($classId, $userId, $isAdmin),
            'class' => [
                'id' => (int) $classroom['id'],
            ],
        ];
    }

    public function spin(int $classId, int $viewerUserId, bool $isAdmin, ?int $requestedStudentId = null, ?string $clientToken = null): array
    {
        $classroom = $this->accessibleClassroom($classId, $viewerUserId, $isAdmin);

        return Database::transaction(function () use ($classId, $classroom, $viewerUserId, $requestedStudentId, $clientToken): array {
            $previous = $this->claimClientToken($clientToken, $classId, 'spin', $viewerUserId);
            if ($previous !== null) {
                $evaluation = $previous['evaluation_id'] === null ? null : $this->evaluationById((int) $previous['evaluation_id']);
                return [
                    'message' => 'Sorteo ya registrado.',
                    'replayed' => true,
                    'selected' => $evaluation === null ? null : $this->formatEvaluation($evaluation),
                    'state' => $this->runtimeState($classId, $classroom, $this->currentCycle($classId)),
                ];
            }

            $cycle = $this->currentCycle($classId);
            if ($cycle['status'] !== 'open') {
                throw new RuntimeException('La ronda actual ya está completa. Reinicia para empezar otra.');
            }

            if ($this->pendingEvaluation($classId, (int) $cycle['id']) !== null) {
                throw new RuntimeException('Debes evaluar al alumno actual antes de volver a girar.');
            }

            $students = $this->remainingStudents($classId, (int) $cycle['id']);
            if ($students === []) {
                $this->markCycleCompleted((int) $cycle['id']);
                throw new RuntimeException('No quedan alumnos en esta ronda. Reinicia la clase para comenzar otra.');
            }

            $selectedStudent = null;
            if ($requestedStudentId !== null) {
                foreach ($students as $student) {
                    if ((int) $student['id'] === $requestedStudentId) {
                        $selectedStudent = $student;
                        break;
                    }
                }

                // Never swap silently to another student: the browser already showed this one.
                if ($selectedStudent === null) {
                    throw new RuntimeException('Ese alumno ya no está pendiente en esta ronda.');
                }
            } else {
                $selectedStudent = $students[random_int(0, count($students) - 1)];
            }

            $statement = Database::connection()->prepare(
                'INSERT INTO evaluations (class_id, student_id, cycle_id, score, selected_at, evaluated_by, evaluated_at)
                 VALUES (:class_id, :student_id, :cycle_id, :score, :selected_at, :evaluated_by, :evaluated_at)'
            );
            $statement->execute([
                'class_id' => $classId,
                'student_id' => (int) $selectedStudent['id'],
                'cycle_id' => (int) $cycle['id'],
                'score' => null,
                'selected_at' => date('Y-m-d H:i:s'),
                'evaluated_by' => null,
                'evaluated_at' => null,
            ]);

            if ($this->remainingStudents($classId, (int) $cycle['id']) === []) {
                $this->markCycleCompleted((int) $cycle['id']);
                $cycle = $this->fetchCycle((int) $cycle['id']);
            }

            $evaluation = $this->pendingEvaluation($classId, (int) $cycle['id']);
            if ($evaluation === null) {
                throw new RuntimeException('No se pudo registrar el sorteo.');
            }

            $this->recordClientTokenEvaluation($clientToken, (int) $evaluation['id']);

            return [
                'message' => 'Alumno seleccionado.',
                'selected' => $this->formatEvaluation($evaluation),
                'state' => $this->runtimeState($classId, $classroom, $cycle),
            ];
        });
    }

    /**
     * Scores an evaluation, identified by its id or by the client token of the spin that created it.
     */
    public function evaluate(
        int $classId,
        int $evaluationId,
        string $score,
        int $userId,
        bool $isAdmin,
        ?string $spinToken = null,
        ?string $clientToken = null
    ): array {
        $classroom = $this->accessibleClassroom($classId, $userId, $isAdmin);
        if (!in_array($score, ['+', '=', '-'], true)) {
            throw new RuntimeException('La calificación no es válida.');
        }

        return Database::transaction(function () use ($classId, $classroom, $evaluationId, $score, $userId, $spinToken, $clientToken): array {
            $previous = $this->claimClientToken($clientToken, $classId, 'evaluate', $userId);
            if ($previous !== null) {
                return [
                    'message' => 'Evaluación ya registrada.',
                    'replayed' => true,
                    'state' => $this->runtimeState($classId, $classroom, $this->currentCycle($classId)),
                ];
            }

            $spinEvaluationId = $spinToken === null || $spinToken === '' ? null : $this->evaluationIdForSpinToken($classId, $spinToken);
            if ($spinEvaluationId !== null) {
                $evaluationId = $spinEvaluationId;
            } elseif ($evaluationId <= 0) {
                throw new RuntimeException('El sorteo de esta evaluación no llegó al servidor.');
            }

            $evaluation = $this->evaluationById($evaluationId);
            if ($evaluation === null || (int) $evaluation['class_id'] !== $classId) {
                throw new RuntimeException('No se encontró la evaluación.');
            }

            $statement = Database::connection()->prepare(
                'UPDATE evaluations
                 SET score = :score, evaluated_by = :evaluated_by, evaluated_at = :evaluated_at
                 WHERE id = :id AND class_id = :class_id'
            );
            $statement->execute([
                'score' => $score,
                'evaluated_by' => $userId,
                'evaluated_at' => date('Y-m-d H:i:s'),
                'id' => $evaluationId,
                'class_id' => $classId,
            ]);

            $this->recordClientTokenEvaluation($clientToken, $evaluationId);

            $cycleId = (int) $evaluation['cycle_id'];
            $latestCycle = $this->currentCycle($classId);
            $isLatestCycle = (int) $latestCycle['id'] === $cycleId;

            if ($isLatestCycle && $this->remainingStudents($classId, $cycleId) === [] && $this->pendingEvaluation($classId, $cycleId) === null) {
                $this->markCycleCompleted($cycleId);
                $this->createNextCycle($classId, (int) $latestCycle['cycle_number']);

                return [
                    'message' => 'Evaluación guardada. Nueva ronda creada automáticamente.',
                    'state' => $this->runtimeState($classId, $classroom, $this->currentCycle($classId)),
                ];
            }

            return [
                'message' => 'Evaluación guardada.',
                'state' => $this->runtimeState($classId, $classroom, $this->currentCycle($classId)),
            ];
        });
    }

    public function resetCycle(int $classId, int $viewerUserId, bool $isAdmin, ?string $clientToken = null): array
    {
        $this->accessibleClassroom($classId, $viewerUserId, $isAdmin);

        return Database::transaction(function () use ($classId, $viewerUserId, $isAdmin, $clientToken): array {
            $previous = $this->claimClientToken($clientToken, $classId, 'reset', $viewerUserId);
            if ($previous !== null) {
                return [
                    'message' => 'Nueva ronda ya registrada.',
                    'replayed' => true,
                    'state' => $this->state($classId, $viewerUserId, $isAdmin),
                ];
            }

            $cycle = $this->currentCycle($classId);
            if ($this->pendingEvaluation($classId, (int) $cycle['id']) !== null) {
                throw new RuntimeException('Evalúa al alumno actual antes de reiniciar la ronda.');
            }

            if ($cycle['status'] !== 'completed') {
                $this->markCycleCompleted((int) $cycle['id']);
            }

            $this->createNextCycle($classId, (int) $cycle['cycle_number']);

            return [
                'message' => 'Nueva ronda creada.',
                'state' => $this->state($classId, $viewerUserId, $isAdmin),
            ];
        });
    }

    /**
     * Registers a client token so a retried request is not applied twice.
     * Returns the earlier row when the token was already used, null when it is new (or absent).
     */
    private function claimClientToken(?string $clientToken, int $classId, string $type, int $userId): ?array
    {
        if ($clientToken === null || $clientToken === '') {
            return null;
        }

        if (strlen($clientToken) > 80 || preg_match('/^[A-Za-z0-9_-]+$/', $clientToken) !== 1) {
            throw new RuntimeException('Identificador de acción no válido.');
        }

        $existing = $this->findClientToken($clientToken);
        if ($existing === null) {
            try {
                $statement = Database::connection()->prepare(
                    'INSERT INTO sync_actions (client_token, class_id, action_type, evaluation_id, created_by, created_at)
                     VALUES (:client_token, :class_id, :action_type, NULL, :created_by, :created_at)'
                );
                $statement->execute([
                    'client_token' => $clientToken,
                    'class_id' => $classId,
                    'action_type' => $type,
                    'created_by' => $userId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                return null;
            } catch (PDOException $exception) {
                // A concurrent retry claimed it first.
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
                $existing = $this->findClientToken($clientToken);
            }
        }

        if ($existing === null || (int) $existing['class_id'] !== $classId || $existing['action_type'] !== $type) {
            throw new RuntimeException('Identificador de acción no válido.');
        }

        return $existing;
    }

    private function findClientToken(string $clientToken): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM sync_actions WHERE client_token = :client_token LIMIT 1');
        $statement->execute(['client_token' => $clientToken]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function recordClientTokenEvaluation(?string $clientToken, int $evaluationId): void
    {
        if ($clientToken === null || $clientToken === '') {
            return;
        }

        $statement = Database::connection()->prepare(
            'UPDATE sync_actions SET evaluation_id = :evaluation_id WHERE client_token = :client_token'
        );
        $statement->execute([
            'evaluation_id' => $evaluationId,
            'client_token' => $clientToken,
        ]);
    }

    private function evaluationIdForSpinToken(int $classId, string $spinToken): ?int
    {
        $statement = Database::connection()->prepare(
            "SELECT evaluation_id FROM sync_actions
             WHERE client_token = :client_token AND class_id = :class_id AND action_type = 'spin'
             LIMIT 1"
        );
        $statement->execute([
            'client_token' => $spinToken,
            'class_id' => $classId,
        ]);
        $evaluationId = $statement->fetchColumn();
        return $evaluationId === false || $evaluationId === null ? null : (int) $evaluationId;
    }

    private function evaluationById(int $evaluationId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT e.id, e.class_id, e.cycle_id, e.score, e.selected_at, e.evaluated_at, s.id AS student_id, s.student_code, s.display_name,
                    EXISTS(SELECT 1 FROM vip_students v WHERE v.class_id = e.class_id AND v.student_id = s.id AND v.ended_at IS NULL) AS is_vip
             FROM evaluations e
             INNER JOIN students s ON s.id = e.student_id
             WHERE e.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $evaluationId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function normalizeAttendanceDate(?string $attendanceDate): string
    {
        $today = date('Y-m-d');
        if ($attendanceDate === null || $attendanceDate === '') {
            return $today;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $attendanceDate);
        if ($parsed === false || $parsed->format('Y-m-d') !== $attendanceDate) {
            throw new RuntimeException('La fecha de asistencia no es válida.');
        }

        // One day of margin for timezone differences between browser and server.
        if ($attendanceDate > date('Y-m-d', strtotime('+1 day'))) {
            throw new RuntimeException('La fecha de asistencia está en el futuro.');
        }

        return $attendanceDate;
    }

    private function latestAttendanceMark(int $classId, string $attendanceDate): ?string
    {
        $statement = Database::connection()->prepare(
            'SELECT MAX(marked_at) FROM attendance_records WHERE class_id = :class_id AND attendance_date = :attendance_date'
        );
        $statement->execute([
            'class_id' => $classId,
            'attendance_date' => $attendanceDate,
        ]);
        $latest = $statement->fetchColumn();
        return $latest === false || $latest === null ? null : (string) $latest;
    }

    private function currentCycle(int $classId): array
    {
        $cycle = $this->classes->latestCycle($classId);
        if ($cycle !== null) {
            return $cycle;
        }

        $this->createNextCycle($classId, 0);

        return $this->classes->latestCycle($classId) ?? throw new RuntimeException('No se pudo crear la ronda inicial.');
    }

    private function createNextCycle(int $classId, int $currentCycleNumber): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO evaluation_cycles (class_id, cycle_number, status, started_at, finished_at)
             VALUES (:class_id, :cycle_number, :status, :started_at, :finished_at)'
        );
        $statement->execute([
            'class_id' => $classId,
            'cycle_number' => $currentCycleNumber + 1,
            'status' => 'open',
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => null,
        ]);
    }

    private function fetchCycle(int $cycleId): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM evaluation_cycles WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $cycleId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('Ronda no encontrada.');
    }

    private function remainingStudents(int $classId, int $cycleId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT s.id, s.student_code, s.display_name,
                    EXISTS(SELECT 1 FROM vip_students v WHERE v.class_id = cs.class_id AND v.student_id = s.id AND v.ended_at IS NULL) AS is_vip
             FROM class_students cs
             INNER JOIN students s ON s.id = cs.student_id
             LEFT JOIN evaluations e
                ON e.student_id = cs.student_id
               AND e.class_id = cs.class_id
               AND e.cycle_id = :cycle_id
             WHERE cs.class_id = :class_id
               AND cs.is_active = 1
               AND e.id IS NULL
             ORDER BY COALESCE(s.display_name, s.student_code) ASC'
        );
        $statement->execute([
            'class_id' => $classId,
            'cycle_id' => $cycleId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pendingEvaluation(int $classId, int $cycleId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT e.id, e.score, e.selected_at, e.evaluated_at, s.id AS student_id, s.student_code, s.display_name,
                    EXISTS(SELECT 1 FROM vip_students v WHERE v.class_id = e.class_id AND v.student_id = s.id AND v.ended_at IS NULL) AS is_vip
             FROM evaluations e
             INNER JOIN students s ON s.id = e.student_id
             WHERE e.class_id = :class_id
               AND e.cycle_id = :cycle_id
               AND e.score IS NULL
             ORDER BY e.selected_at DESC
             LIMIT 1'
        );
        $statement->execute([
            'class_id' => $classId,
            'cycle_id' => $cycleId,
        ]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function recentEvaluations(int $classId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT e.id, e.score, e.evaluated_at, s.student_code, s.display_name
             FROM evaluations e
             INNER JOIN students s ON s.id = e.student_id
             WHERE e.class_id = :class_id
               AND e.score IS NOT NULL
             ORDER BY e.evaluated_at DESC
             LIMIT 8'
        );
        $statement->execute(['class_id' => $classId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'score' => $row['score'],
                'evaluated_at' => $row['evaluated_at'],
                'student' => $this->studentLabel($row),
            ];
        }, $rows);
    }

    private function cycleEvaluatedCount(int $cycleId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM evaluations WHERE cycle_id = :cycle_id AND score IS NOT NULL'
        );
        $statement->execute(['cycle_id' => $cycleId]);
        return (int) $statement->fetchColumn();
    }

    private function cycleIdByEvaluation(int $evaluationId): ?int
    {
        $statement = Database::connection()->prepare('SELECT cycle_id FROM evaluations WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $evaluationId]);
        $cycleId = $statement->fetchColumn();
        return $cycleId === false ? null : (int) $cycleId;
    }

    private function markCycleCompleted(int $cycleId): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE evaluation_cycles
             SET status = :status, finished_at = :finished_at
             WHERE id = :id'
        );
        $statement->execute([
            'status' => 'completed',
            'finished_at' => date('Y-m-d H:i:s'),
            'id' => $cycleId,
        ]);
    }

    private function accessibleClassroom(int $classId, int $viewerUserId, bool $isAdmin): array
    {
        $classroom = $this->classes->find($classId, $viewerUserId, $isAdmin);
        if ($classroom === null) {
            throw new RuntimeException('Clase no encontrada.');
        }

        return $classroom;
    }

    private function formatStudent(array $student): array
    {
        return [
            'id' => (int) $student['id'],
            'code' => $student['student_code'],
            'name' => $student['display_name'],
            'label' => $this->studentLabel($student),
            'vip' => !empty($student['is_vip']),
        ];
    }

    private function formatEvaluation(array $evaluation): array
    {
        return [
            'id' => (int) $evaluation['id'],
            'score' => $evaluation['score'],
            'selected_at' => $evaluation['selected_at'],
            'evaluated_at' => $evaluation['evaluated_at'],
            'student' => [
                'id' => (int) $evaluation['student_id'],
                'code' => $evaluation['student_code'],
                'name' => $evaluation['display_name'],
                'label' => $this->studentLabel($evaluation),
                'vip' => !empty($evaluation['is_vip']),
            ],
        ];
    }

    private function studentLabel(array $student): string
    {
        $name = trim((string) ($student['display_name'] ?? ''));
        $code = trim((string) ($student['student_code'] ?? ''));

        $label = $name !== '' ? $name . ' (' . $code . ')' : $code;

        return empty($student['is_vip']) ? $label : $label . ' ★ VIP';
    }

    private function runtimeState(int $classId, array $classroom, array $cycle): array
    {
        $cycleId = (int) $cycle['id'];
        $remainingStudents = $this->remainingStudents($classId, $cycleId);
        $pendingEvaluation = $this->pendingEvaluation($classId, $cycleId);
        $evaluatedCount = $this->cycleEvaluatedCount($cycleId);

        return [
            'class' => [
                'id' => (int) $classroom['id'],
                'code' => $classroom['code'],
                'name' => $classroom['name'],
                'term' => $classroom['term'],
                'year' => (int) $classroom['year'],
                'owner_username' => $classroom['owner_username'] ?? null,
                'students_count' => (int) $classroom['students_count'],
            ],
            'cycle' => [
                'id' => $cycleId,
                'number' => (int) $cycle['cycle_number'],
                'status' => $cycle['status'],
                'started_at' => $cycle['started_at'],
                'finished_at' => $cycle['finished_at'],
            ],
            'remaining_students' => array_map([$this, 'formatStudent'], $remainingStudents),
            'pending_evaluation' => $pendingEvaluation === null ? null : $this->formatEvaluation($pendingEvaluation),
            'recent_evaluations' => $this->recentEvaluations($classId),
            'attendance' => $this->attendanceState($classId),
            'stats' => [
                'total_students' => (int) $classroom['students_count'],
                'remaining_students' => count($remainingStudents),
                'evaluated_students' => $evaluatedCount,
                'can_spin' => $cycle['status'] === 'open' && $pendingEvaluation === null && count($remainingStudents) > 0,
                'can_reset' => (int) $classroom['students_count'] > 0 && $pendingEvaluation === null,
            ],
        ];
    }

    private function attendanceState(int $classId): array
    {
        $attendanceDate = date('Y-m-d');
        $students = $this->classes->studentsForClass($classId);

        return [
            'date' => $attendanceDate,
            'students' => array_map([$this, 'formatStudent'], $students),
            'present_student_ids' => $this->presentStudentIdsByDate($classId, $attendanceDate),
            'taken' => $this->latestAttendanceMark($classId, $attendanceDate) !== null,
        ];
    }

    private function presentStudentIdsByDate(int $classId, string $attendanceDate): array
    {
        $statement = Database::connection()->prepare(
            'SELECT student_id
             FROM attendance_records
             WHERE class_id = :class_id
               AND attendance_date = :attendance_date
               AND attendance_score = 2'
        );
        $statement->execute([
            'class_id' => $classId,
            'attendance_date' => $attendanceDate,
        ]);

        return array_map(static fn(mixed $id): int => (int) $id, $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
