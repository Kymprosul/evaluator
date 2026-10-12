<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClassRepository;
use RuntimeException;

/**
 * Applies the offline backup file the classroom page lets the teacher download when the
 * server cannot be reached. Every action carries the same client token the browser queue
 * uses, so uploading a file twice, or uploading it after the browser synced, changes nothing.
 */
final class BackupService
{
    private const FORMAT = 'evaluator-backup';
    private const MAX_ACTIONS = 1000;
    private const MAX_ATTENDANCE_DAYS = 60;

    public function __construct(
        private readonly EvaluationService $evaluations = new EvaluationService(),
        private readonly ClassRepository $classes = new ClassRepository()
    ) {
    }

    public function parse(string $json): array
    {
        $payload = json_decode($json, true);
        if (!is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT || (int) ($payload['version'] ?? 0) !== 1) {
            throw new RuntimeException('El archivo no es un respaldo de Evaluator.');
        }

        $classId = (int) ($payload['class']['id'] ?? 0);
        if ($classId <= 0) {
            throw new RuntimeException('El respaldo no indica la clase.');
        }

        $actions = is_array($payload['actions'] ?? null) ? array_values($payload['actions']) : [];
        $attendance = is_array($payload['attendance'] ?? null) ? array_values($payload['attendance']) : [];

        if (count($actions) > self::MAX_ACTIONS || count($attendance) > self::MAX_ATTENDANCE_DAYS) {
            throw new RuntimeException('El respaldo es demasiado grande.');
        }

        return [
            'class_id' => $classId,
            'created_at' => (string) ($payload['created_at'] ?? ''),
            'actions' => array_values(array_filter(array_map([$this, 'normalizeAction'], $actions))),
            'attendance' => array_values(array_filter(array_map([$this, 'normalizeAttendance'], $attendance))),
        ];
    }

    public function preview(array $backup, int $userId, bool $isAdmin): array
    {
        $classroom = $this->classes->find($backup['class_id'], $userId, $isAdmin)
            ?? throw new RuntimeException('La clase del respaldo no existe o no tienes acceso.');

        return [
            'class_label' => $this->classes->label($classroom),
            'created_at' => $backup['created_at'],
            'actions' => array_map(fn(array $action): array => [
                'type' => $action['type'],
                'description' => $this->describe($action),
            ], $backup['actions']),
            'attendance' => array_map(static fn(array $day): array => [
                'date' => $day['date'],
                'present_count' => count($day['ids']),
            ], $backup['attendance']),
        ];
    }

    /**
     * @return list<array{description: string, status: 'applied'|'duplicate'|'skipped', message: string}>
     */
    public function apply(array $backup, int $userId, bool $isAdmin): array
    {
        $classId = $backup['class_id'];
        $results = [];
        $failedSpins = [];

        foreach ($backup['actions'] as $action) {
            $description = $this->describe($action);

            if ($action['type'] === 'evaluate' && $action['spin_token'] !== null && isset($failedSpins[$action['spin_token']])) {
                $results[] = $this->result($description, 'skipped', 'Su sorteo no se pudo aplicar.');
                continue;
            }

            try {
                $data = match ($action['type']) {
                    'spin' => $this->evaluations->spin($classId, $userId, $isAdmin, $action['student_id'], $action['token']),
                    'evaluate' => $this->evaluations->evaluate(
                        $classId,
                        (int) $action['evaluation_id'],
                        $action['score'],
                        $userId,
                        $isAdmin,
                        $action['spin_token'],
                        $action['token']
                    ),
                    'reset' => $this->evaluations->resetCycle($classId, $userId, $isAdmin, $action['token']),
                };

                $results[] = !empty($data['replayed'])
                    ? $this->result($description, 'duplicate', 'Ya estaba en el servidor.')
                    : $this->result($description, 'applied', 'Aplicado.');
            } catch (RuntimeException $exception) {
                if ($action['type'] === 'spin') {
                    $failedSpins[$action['token']] = true;
                }
                $results[] = $this->result($description, 'skipped', $exception->getMessage());
            }
        }

        foreach ($backup['attendance'] as $day) {
            $description = 'Asistencia ' . $day['date'] . ' (' . count($day['ids']) . ' presentes)';

            try {
                $data = $this->evaluations->saveAttendance($classId, $day['ids'], $day['date'], $userId, $isAdmin, $day['ts']);
                $results[] = !empty($data['skipped'])
                    ? $this->result($description, 'skipped', (string) $data['message'])
                    : $this->result($description, 'applied', 'Aplicado.');
            } catch (RuntimeException $exception) {
                $results[] = $this->result($description, 'skipped', $exception->getMessage());
            }
        }

        return $results;
    }

    private function normalizeAction(mixed $action): ?array
    {
        if (!is_array($action) || !in_array($action['type'] ?? null, ['spin', 'evaluate', 'reset'], true)) {
            return null;
        }

        $token = (string) ($action['token'] ?? '');
        if ($token === '') {
            return null;
        }

        $spinToken = (string) ($action['spinToken'] ?? '');

        return [
            'type' => $action['type'],
            'token' => $token,
            'student_id' => isset($action['studentId']) ? (int) $action['studentId'] : null,
            'student_label' => mb_substr((string) ($action['studentLabel'] ?? ''), 0, 120),
            'evaluation_id' => isset($action['evaluationId']) ? (int) $action['evaluationId'] : null,
            'spin_token' => $spinToken === '' ? null : $spinToken,
            'score' => (string) ($action['score'] ?? ''),
        ];
    }

    private function normalizeAttendance(mixed $day): ?array
    {
        if (!is_array($day) || !is_string($day['date'] ?? null) || !is_array($day['ids'] ?? null)) {
            return null;
        }

        return [
            'date' => $day['date'],
            'ids' => array_values(array_unique(array_map('intval', $day['ids']))),
            'ts' => isset($day['ts']) ? (int) $day['ts'] : null,
        ];
    }

    private function describe(array $action): string
    {
        $student = $action['student_label'] !== '' ? $action['student_label'] : 'alumno #' . ($action['student_id'] ?? '?');

        return match ($action['type']) {
            'spin' => 'Sorteo: ' . $student,
            'evaluate' => 'Evaluación: ' . $student . ' (' . $action['score'] . ')',
            'reset' => 'Nueva ronda',
        };
    }

    private function result(string $description, string $status, string $message): array
    {
        return ['description' => $description, 'status' => $status, 'message' => $message];
    }
}
