<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class ClassRepository
{
    public function allWithStats(int $viewerUserId, bool $isAdmin): array
    {
        [$sql, $parameters] = $this->scopedClassQuery($viewerUserId, $isAdmin, false);
        $statement = Database::connection()->prepare($sql);
        $statement->execute($parameters);
        $classes = $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($classes as &$classroom) {
            $classroom['students_count'] = $this->studentCount((int) $classroom['id']);
            $classroom['latest_cycle'] = $this->latestCycle((int) $classroom['id']);
        }

        return $classes;
    }

    public function allActive(int $viewerUserId, bool $isAdmin): array
    {
        [$sql, $parameters] = $this->scopedClassQuery($viewerUserId, $isAdmin, true);
        $statement = Database::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function allActiveWithStats(int $viewerUserId, bool $isAdmin): array
    {
        $classes = $this->allActive($viewerUserId, $isAdmin);

        foreach ($classes as &$classroom) {
            $classroom['students_count'] = $this->studentCount((int) $classroom['id']);
            $classroom['latest_cycle'] = $this->latestCycle((int) $classroom['id']);
        }

        return $classes;
    }

    public function guestClassesGroupedByUsername(): array
    {
        $statement = Database::connection()->prepare(
            'SELECT c.*, u.username AS owner_username
             FROM classes c
             INNER JOIN users u ON u.id = c.owner_user_id
             WHERE u.is_admin = 0
             ORDER BY u.username ASC, c.year DESC, c.name ASC'
        );
        $statement->execute();
        $classes = $statement->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($classes as $classroom) {
            $classroom['students_count'] = $this->studentCount((int) $classroom['id']);
            $classroom['latest_cycle'] = $this->latestCycle((int) $classroom['id']);
            $grouped[$classroom['owner_username']][] = $classroom;
        }

        return $grouped;
    }

    public function create(array $data, int $ownerUserId): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO classes (code, name, term, year, is_active, owner_user_id, created_at)
             VALUES (:code, :name, :term, :year, :is_active, :owner_user_id, :created_at)'
        );

        $statement->execute([
            'code' => $this->uniqueCode($ownerUserId, trim((string) ($data['code'] ?? ''))),
            'name' => trim((string) ($data['name'] ?? '')),
            'term' => trim((string) ($data['term'] ?? '')),
            'year' => (int) ($data['year'] ?? 0),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'owner_user_id' => $ownerUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE classes
             SET code = :code, name = :name, term = :term, year = :year, is_active = :is_active
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
            'code' => trim((string) ($data['code'] ?? '')),
            'name' => trim((string) ($data['name'] ?? '')),
            'term' => trim((string) ($data['term'] ?? '')),
            'year' => (int) ($data['year'] ?? 0),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ]);
    }

    public function delete(int $id): void
    {
        $statement = Database::connection()->prepare('DELETE FROM classes WHERE id = :id');
        $statement->execute(['id' => $id]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('No se pudo eliminar la clase.');
        }
    }

    public function find(int $id, int $viewerUserId, bool $isAdmin): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT c.*, u.username AS owner_username
             FROM classes c
             LEFT JOIN users u ON u.id = c.owner_user_id
             WHERE c.id = :id' . ($isAdmin ? '' : ' AND c.owner_user_id = :owner_user_id') . '
             LIMIT 1'
        );
        $parameters = ['id' => $id];
        if (!$isAdmin) {
            $parameters['owner_user_id'] = $viewerUserId;
        }
        $statement->execute($parameters);
        $classroom = $statement->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($classroom !== null) {
            $classroom['students_count'] = $this->studentCount($id);
        }

        return $classroom;
    }

    public function label(array $classroom): string
    {
        $name = trim((string) ($classroom['name'] ?? ''));
        $code = trim((string) ($classroom['code'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        return $code;
    }

    public function studentsForClass(int $classId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT s.id, s.student_code, s.display_name
             FROM class_students cs
             INNER JOIN students s ON s.id = cs.student_id
             WHERE cs.class_id = :class_id AND cs.is_active = 1
             ORDER BY COALESCE(s.display_name, s.student_code) ASC'
        );
        $statement->execute(['class_id' => $classId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addStudentToClass(int $classId, string $studentCode, ?string $displayName): void
    {
        $studentCode = trim($studentCode);
        $displayName = $displayName === null ? null : trim($displayName);

        if ($classId <= 0) {
            throw new RuntimeException('Clase no válida.');
        }

        if ($studentCode === '') {
            throw new RuntimeException('El código del alumno es obligatorio.');
        }

        if ($displayName === '') {
            $displayName = null;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare('SELECT id FROM students WHERE student_code = :student_code LIMIT 1');
            $statement->execute(['student_code' => $studentCode]);
            $studentId = $statement->fetchColumn();

            if ($studentId === false) {
                $statement = $pdo->prepare(
                    'INSERT INTO students (student_code, display_name, created_at)
                     VALUES (:student_code, :display_name, :created_at)'
                );
                $statement->execute([
                    'student_code' => $studentCode,
                    'display_name' => $displayName,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $studentId = (int) $pdo->lastInsertId();
            } else {
                $studentId = (int) $studentId;
                if ($displayName !== null && $displayName !== '') {
                    $statement = $pdo->prepare('UPDATE students SET display_name = :display_name WHERE id = :id');
                    $statement->execute([
                        'display_name' => $displayName,
                        'id' => $studentId,
                    ]);
                }
            }

            $statement = $pdo->prepare(
                'SELECT id FROM class_students WHERE class_id = :class_id AND student_id = :student_id LIMIT 1'
            );
            $statement->execute([
                'class_id' => $classId,
                'student_id' => $studentId,
            ]);
            $linkId = $statement->fetchColumn();

            if ($linkId === false) {
                $statement = $pdo->prepare(
                    'INSERT INTO class_students (class_id, student_id, is_active, created_at)
                     VALUES (:class_id, :student_id, :is_active, :created_at)'
                );
                $statement->execute([
                    'class_id' => $classId,
                    'student_id' => $studentId,
                    'is_active' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $statement = $pdo->prepare('UPDATE class_students SET is_active = 1 WHERE id = :id');
                $statement->execute(['id' => (int) $linkId]);
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function removeStudentFromClass(int $classId, int $studentId): void
    {
        if ($classId <= 0 || $studentId <= 0) {
            throw new RuntimeException('Alumno o clase no válidos.');
        }

        $statement = Database::connection()->prepare(
            'DELETE FROM class_students WHERE class_id = :class_id AND student_id = :student_id'
        );
        $statement->execute([
            'class_id' => $classId,
            'student_id' => $studentId,
        ]);
    }

    public function studentCount(int $classId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*)
             FROM class_students
             WHERE class_id = :class_id AND is_active = 1'
        );
        $statement->execute(['class_id' => $classId]);
        return (int) $statement->fetchColumn();
    }

    public function latestCycle(int $classId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM evaluation_cycles WHERE class_id = :class_id ORDER BY cycle_number DESC LIMIT 1'
        );
        $statement->execute(['class_id' => $classId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function scopedClassQuery(int $viewerUserId, bool $isAdmin, bool $onlyActive): array
    {
        $conditions = [];
        $parameters = [];

        if ($onlyActive) {
            $conditions[] = 'c.is_active = 1';
        }

        if (!$isAdmin) {
            $conditions[] = 'c.owner_user_id = :owner_user_id';
            $parameters['owner_user_id'] = $viewerUserId;
        }

        $whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return [
            'SELECT c.*, u.username AS owner_username
             FROM classes c
             LEFT JOIN users u ON u.id = c.owner_user_id' . $whereSql . '
             ORDER BY c.year DESC, c.term ASC, c.name ASC',
            $parameters,
        ];
    }

    private function uniqueCode(int $ownerUserId, string $requestedCode): string
    {
        if ($requestedCode !== '') {
            return $requestedCode;
        }

        return 'class-' . $ownerUserId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
    }
}
