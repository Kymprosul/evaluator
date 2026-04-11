<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use RuntimeException;

final class ImportService
{
    private const MAX_IMPORT_SIZE_BYTES = 1048576;

    public function importClassFile(int $classId, array $uploadedFile): array
    {
        if ($classId <= 0) {
            throw new RuntimeException('Selecciona una clase válida.');
        }

        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo subir el archivo.');
        }

        $temporaryPath = (string) ($uploadedFile['tmp_name'] ?? '');
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            throw new RuntimeException('El archivo subido no es válido.');
        }

        $fileSize = (int) ($uploadedFile['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::MAX_IMPORT_SIZE_BYTES) {
            throw new RuntimeException('El archivo .txt es demasiado grande o no es válido.');
        }

        $extension = strtolower((string) pathinfo((string) ($uploadedFile['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension !== 'txt') {
            throw new RuntimeException('El archivo debe estar en formato .txt.');
        }

        $contents = file_get_contents($temporaryPath);
        if ($contents === false) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }

        $students = $this->parseStudents($contents);
        if ($students === []) {
            throw new RuntimeException('El archivo no contiene alumnos válidos.');
        }

        $safeOriginalName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $uploadedFile['name']);
        $storedName = date('Ymd_His') . '_' . $safeOriginalName;
        $destination = storage_path('imports/' . $storedName);

        if (!move_uploaded_file($temporaryPath, $destination)) {
            throw new RuntimeException('No se pudo guardar el archivo importado.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $inserted = 0;
            $linked = 0;

            foreach ($students as $student) {
                $studentId = $this->findStudentIdByCode($student['student_code']);
                if ($studentId === null) {
                    $statement = $pdo->prepare(
                        'INSERT INTO students (student_code, display_name, created_at)
                         VALUES (:student_code, :display_name, :created_at)'
                    );
                    $statement->execute([
                        'student_code' => $student['student_code'],
                        'display_name' => $student['display_name'],
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $studentId = (int) $pdo->lastInsertId();
                    $inserted++;
                } elseif ($student['display_name'] !== null) {
                    $statement = $pdo->prepare(
                        'UPDATE students
                         SET display_name = :display_name
                         WHERE id = :id AND (display_name IS NULL OR display_name = \'\')'
                    );
                    $statement->execute([
                        'display_name' => $student['display_name'],
                        'id' => $studentId,
                    ]);
                }

                if (!$this->classHasStudent($classId, $studentId)) {
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
                    $linked++;
                }
            }

            $statement = $pdo->prepare(
                'INSERT INTO imports (class_id, file_name, imported_at)
                 VALUES (:class_id, :file_name, :imported_at)'
            );
            $statement->execute([
                'class_id' => $classId,
                'file_name' => $storedName,
                'imported_at' => date('Y-m-d H:i:s'),
            ]);

            $pdo->commit();

            return [
                'parsed' => count($students),
                'inserted_students' => $inserted,
                'linked_students' => $linked,
            ];
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    private function parseStudents(string $contents): array
    {
        $rows = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $students = [];

        foreach ($rows as $row) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }

            $parts = explode(';', $row, 2);
            $studentCode = trim((string) ($parts[0] ?? ''));
            $displayName = trim((string) ($parts[1] ?? ''));

            if ($studentCode === '') {
                continue;
            }

            $students[$studentCode] = [
                'student_code' => $studentCode,
                'display_name' => $displayName === '' ? null : $displayName,
            ];
        }

        return array_values($students);
    }

    private function findStudentIdByCode(string $studentCode): ?int
    {
        $statement = Database::connection()->prepare('SELECT id FROM students WHERE student_code = :student_code LIMIT 1');
        $statement->execute(['student_code' => $studentCode]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function classHasStudent(int $classId, int $studentId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM class_students WHERE class_id = :class_id AND student_id = :student_id'
        );
        $statement->execute([
            'class_id' => $classId,
            'student_id' => $studentId,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }
}
