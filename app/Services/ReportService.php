<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Repositories\ClassRepository;
use PDO;
use RuntimeException;

final class ReportService
{
    private const MISSING_SCORE_LABEL = 'NA';

    public function __construct(
        private readonly ClassRepository $classes = new ClassRepository()
    ) {
    }

    public function reportMatrix(int $classId, int $viewerUserId, bool $isAdmin): array
    {
        $classroom = $this->accessibleClassroom($classId, $viewerUserId, $isAdmin);

        $cyclesStatement = Database::connection()->prepare(
            'SELECT id, cycle_number
            FROM evaluation_cycles
             WHERE class_id = :class_id
             ORDER BY cycle_number ASC'
        );
        $cyclesStatement->execute(['class_id' => $classId]);
        $cycles = $cyclesStatement->fetchAll(PDO::FETCH_ASSOC);
        $cycleNumbers = array_map(static fn (array $cycle): int => (int) $cycle['cycle_number'], $cycles);

        $students = $this->classes->studentsForClass($classId);

        $evaluationsStatement = Database::connection()->prepare(
            'SELECT e.id, e.student_id, e.score, COALESCE(ec.cycle_number, e.cycle_id) AS cycle_number
              FROM evaluations e
              LEFT JOIN evaluation_cycles ec
                ON ec.id = e.cycle_id
               AND ec.class_id = e.class_id
              WHERE e.class_id = :class_id
                AND e.score IS NOT NULL
              ORDER BY COALESCE(ec.cycle_number, e.cycle_id) ASC'
        );
        $evaluationsStatement->execute(['class_id' => $classId]);
        $evaluations = $evaluationsStatement->fetchAll(PDO::FETCH_ASSOC);

        $cells = [];
        foreach ($evaluations as $evaluation) {
            $cycleNumber = (int) $evaluation['cycle_number'];

            $cells[(int) $evaluation['student_id']][$cycleNumber] = [
                'evaluation_id' => (int) $evaluation['id'],
                'score' => $evaluation['score'],
            ];

            if (!in_array($cycleNumber, $cycleNumbers, true)) {
                $cycleNumbers[] = $cycleNumber;
            }
        }

        sort($cycleNumbers, SORT_NUMERIC);

        $rows = [];
        foreach ($students as $student) {
            $row = [
                'student_id' => (int) $student['id'],
                'student_label' => trim((string) ($student['display_name'] ?: $student['student_code'])),
                'cycles' => [],
            ];

            foreach ($cycleNumbers as $cycleNumber) {
                $row['cycles'][$cycleNumber] = $cells[(int) $student['id']][$cycleNumber] ?? null;
            }

            $rows[] = $row;
        }

        return [
            'class' => $classroom,
            'class_label' => $this->classes->label($classroom),
            'cycles' => $cycleNumbers,
            'rows' => $rows,
        ];
    }

    public function rows(array $filters = []): array
    {
        [$sql, $parameters] = $this->buildQuery($filters);
        $statement = Database::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function summary(array $rows): array
    {
        $summary = ['+' => 0, '=' => 0, '-' => 0];

        foreach ($rows as $row) {
            if (isset($summary[$row['score']])) {
                $summary[$row['score']]++;
            }
        }

        return $summary;
    }

    public function exportCsv(int $classId, int $viewerUserId, bool $isAdmin): string
    {
        $matrix = $this->reportMatrix($classId, $viewerUserId, $isAdmin);
        $handle = fopen('php://temp', 'r+');
        $headers = array_merge(['Estudiante'], array_map(static fn (int $cycle): string => 'Nota ' . $cycle, $matrix['cycles']));

        fputcsv($handle, [$matrix['class_label']]);
        fputcsv($handle, $headers);

        foreach ($matrix['rows'] as $row) {
            $line = [$row['student_label']];
            foreach ($matrix['cycles'] as $cycleNumber) {
                $line[] = $this->scoreLabel($row['cycles'][$cycleNumber] ?? null);
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    public function exportExcel(int $classId, int $viewerUserId, bool $isAdmin): string
    {
        $matrix = $this->reportMatrix($classId, $viewerUserId, $isAdmin);

        $html = '<table border="1">';
        $colspan = max(1, count($matrix['cycles']) + 1);
        $html .= '<tr><th colspan="' . $colspan . '">' . htmlspecialchars($matrix['class_label'], ENT_QUOTES, 'UTF-8') . '</th></tr>';
        $html .= '<tr><th>Estudiante</th>';
        foreach ($matrix['cycles'] as $cycleNumber) {
            $html .= '<th>Nota ' . $cycleNumber . '</th>';
        }
        $html .= '</tr>';

        foreach ($matrix['rows'] as $row) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars((string) $row['student_label'], ENT_QUOTES, 'UTF-8') . '</td>';
            foreach ($matrix['cycles'] as $cycleNumber) {
                $html .= '<td>' . htmlspecialchars($this->scoreLabel($row['cycles'][$cycleNumber] ?? null), ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</table>';

        return $html;
    }

    public function updateScore(int $evaluationId, string $score, int $userId, bool $isAdmin): void
    {
        if ($evaluationId <= 0) {
            throw new RuntimeException('Evaluación no válida.');
        }

        if (!in_array($score, ['+', '=', '-'], true)) {
            throw new RuntimeException('La calificación no es válida.');
        }

        $classId = $this->classIdByEvaluation($evaluationId);
        if ($classId === null) {
            throw new RuntimeException('No se pudo actualizar la nota.');
        }

        $this->accessibleClassroom($classId, $userId, $isAdmin);

        $statement = Database::connection()->prepare(
            'UPDATE evaluations
             SET score = :score, evaluated_by = :evaluated_by, evaluated_at = :evaluated_at
             WHERE id = :id'
        );
        $statement->execute([
            'score' => $score,
            'evaluated_by' => $userId,
            'evaluated_at' => date('Y-m-d H:i:s'),
            'id' => $evaluationId,
        ]);

        if ($statement->rowCount() === 0) {
            $check = Database::connection()->prepare('SELECT COUNT(*) FROM evaluations WHERE id = :id');
            $check->execute(['id' => $evaluationId]);

            if ((int) $check->fetchColumn() === 0) {
                throw new RuntimeException('No se pudo actualizar la nota.');
            }
        }
    }

    public function upsertScoreByCycle(int $classId, int $studentId, int $cycleNumber, string $score, int $userId, bool $isAdmin): void
    {
        if ($classId <= 0 || $studentId <= 0 || $cycleNumber <= 0) {
            throw new RuntimeException('Datos de nota no válidos.');
        }

        if (!in_array($score, ['', '+', '=', '-'], true)) {
            throw new RuntimeException('La calificación no es válida.');
        }

        $this->accessibleClassroom($classId, $userId, $isAdmin);

        $cycle = $this->findCycle($classId, $cycleNumber);
        if ($cycle === null) {
            throw new RuntimeException('La columna seleccionada no existe.');
        }

        $statement = Database::connection()->prepare(
            'SELECT id FROM evaluations WHERE class_id = :class_id AND student_id = :student_id AND cycle_id = :cycle_id LIMIT 1'
        );
        $statement->execute([
            'class_id' => $classId,
            'student_id' => $studentId,
            'cycle_id' => (int) $cycle['id'],
        ]);
        $evaluationId = $statement->fetchColumn();

        if ($score === '') {
            if ($evaluationId !== false) {
                $delete = Database::connection()->prepare('DELETE FROM evaluations WHERE id = :id');
                $delete->execute(['id' => (int) $evaluationId]);
            }
            return;
        }

        if ($evaluationId === false) {
            $insert = Database::connection()->prepare(
                'INSERT INTO evaluations (class_id, student_id, cycle_id, score, selected_at, evaluated_by, evaluated_at)
                 VALUES (:class_id, :student_id, :cycle_id, :score, :selected_at, :evaluated_by, :evaluated_at)'
            );
            $insert->execute([
                'class_id' => $classId,
                'student_id' => $studentId,
                'cycle_id' => (int) $cycle['id'],
                'score' => $score,
                'selected_at' => date('Y-m-d H:i:s'),
                'evaluated_by' => $userId,
                'evaluated_at' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        $update = Database::connection()->prepare(
            'UPDATE evaluations
             SET score = :score, evaluated_by = :evaluated_by, evaluated_at = :evaluated_at
             WHERE id = :id'
        );
        $update->execute([
            'score' => $score,
            'evaluated_by' => $userId,
            'evaluated_at' => date('Y-m-d H:i:s'),
            'id' => (int) $evaluationId,
        ]);
    }

    public function addCycleColumn(int $classId, int $viewerUserId, bool $isAdmin): int
    {
        if ($classId <= 0) {
            throw new RuntimeException('Clase no válida.');
        }

        $this->accessibleClassroom($classId, $viewerUserId, $isAdmin);

        $statement = Database::connection()->prepare(
            'SELECT MAX(cycle_number) FROM evaluation_cycles WHERE class_id = :class_id'
        );
        $statement->execute(['class_id' => $classId]);
        $nextCycleNumber = (int) $statement->fetchColumn() + 1;

        $insert = Database::connection()->prepare(
            'INSERT INTO evaluation_cycles (class_id, cycle_number, status, started_at, finished_at)
             VALUES (:class_id, :cycle_number, :status, :started_at, :finished_at)'
        );
        $insert->execute([
            'class_id' => $classId,
            'cycle_number' => $nextCycleNumber,
            'status' => 'open',
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => null,
        ]);

        return $nextCycleNumber;
    }

    public function deleteCycleColumn(int $classId, int $cycleNumber, int $viewerUserId, bool $isAdmin): void
    {
        $this->accessibleClassroom($classId, $viewerUserId, $isAdmin);
        $cycle = $this->findCycle($classId, $cycleNumber);
        if ($cycle === null) {
            throw new RuntimeException('La columna seleccionada no existe.');
        }

        $delete = Database::connection()->prepare('DELETE FROM evaluation_cycles WHERE id = :id');
        $delete->execute(['id' => (int) $cycle['id']]);
    }

    private function findCycle(int $classId, int $cycleNumber): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM evaluation_cycles WHERE class_id = :class_id AND cycle_number = :cycle_number LIMIT 1'
        );
        $statement->execute([
            'class_id' => $classId,
            'cycle_number' => $cycleNumber,
        ]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function scoreLabel(?array $cell): string
    {
        $score = $cell['score'] ?? null;
        return is_string($score) && $score !== '' ? $score : self::MISSING_SCORE_LABEL;
    }

    private function accessibleClassroom(int $classId, int $viewerUserId, bool $isAdmin): array
    {
        $classroom = $this->classes->find($classId, $viewerUserId, $isAdmin);
        if ($classroom === null) {
            throw new RuntimeException('Clase no válida.');
        }

        return $classroom;
    }

    private function classIdByEvaluation(int $evaluationId): ?int
    {
        $statement = Database::connection()->prepare('SELECT class_id FROM evaluations WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $evaluationId]);
        $classId = $statement->fetchColumn();

        return $classId === false ? null : (int) $classId;
    }

    private function buildQuery(array $filters): array
    {
        $conditions = ['e.score IS NOT NULL'];
        $parameters = [];

        if (!empty($filters['class_id'])) {
            $conditions[] = 'c.id = :class_id';
            $parameters['class_id'] = (int) $filters['class_id'];
        }

        if (!empty($filters['score'])) {
            $conditions[] = 'e.score = :score';
            $parameters['score'] = (string) $filters['score'];
        }

        if (!empty($filters['from_date'])) {
            $conditions[] = 'e.evaluated_at >= :from_date';
            $parameters['from_date'] = trim((string) $filters['from_date']) . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $conditions[] = 'e.evaluated_at <= :to_date';
            $parameters['to_date'] = trim((string) $filters['to_date']) . ' 23:59:59';
        }

        $sql = '
            SELECT
                e.id,
                e.score,
                e.evaluated_at,
                ec.cycle_number,
                c.name AS class_name,
                c.code AS class_code,
                s.student_code,
                COALESCE(s.display_name, s.student_code) AS student_name
            FROM evaluations e
            INNER JOIN evaluation_cycles ec ON ec.id = e.cycle_id
            INNER JOIN classes c ON c.id = e.class_id
            INNER JOIN students s ON s.id = e.student_id
            WHERE ' . implode(' AND ', $conditions) . '
            ORDER BY e.evaluated_at DESC
        ';

        return [$sql, $parameters];
    }
}
