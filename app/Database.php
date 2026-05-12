<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $driver = (string) app_config('db.driver', 'sqlite');

        if ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                (string) app_config('db.host', '127.0.0.1'),
                (string) app_config('db.port', '3306'),
                (string) app_config('db.database', '')
            );

            self::$connection = new PDO(
                $dsn,
                (string) app_config('db.username', ''),
                (string) app_config('db.password', ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            return self::$connection;
        }

        $databasePath = (string) app_config('db.database');
        $databaseDirectory = dirname($databasePath);
        if (!is_dir($databaseDirectory)) {
            mkdir($databaseDirectory, 0775, true);
        }

        self::$connection = new PDO(
            'sqlite:' . $databasePath,
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        self::$connection->exec('PRAGMA foreign_keys = ON');

        return self::$connection;
    }

    public static function migrate(): void
    {
        $pdo = self::connection();
        $driver = (string) app_config('db.driver', 'sqlite');

        foreach (self::schemaStatements($driver) as $statement) {
            $pdo->exec($statement);
        }

        self::ensureAdditionalColumns($pdo, $driver);
        self::backfillAdminUser($pdo);
        self::backfillClassOwners($pdo);
    }

    private static function schemaStatements(string $driver): array
    {
        return $driver === 'mysql' ? self::mysqlSchema() : self::sqliteSchema();
    }

    private static function sqliteSchema(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS classes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                term TEXT NOT NULL,
                year INTEGER NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                owner_user_id INTEGER NULL,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS students (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_code TEXT NOT NULL UNIQUE,
                display_name TEXT NULL,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS class_students (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id INTEGER NOT NULL,
                student_id INTEGER NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                UNIQUE(class_id, student_id),
                FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE,
                FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
            )',
            'CREATE TABLE IF NOT EXISTS evaluation_cycles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id INTEGER NOT NULL,
                cycle_number INTEGER NOT NULL,
                status TEXT NOT NULL,
                started_at TEXT NOT NULL,
                finished_at TEXT NULL,
                UNIQUE(class_id, cycle_number),
                FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE
            )',
            'CREATE TABLE IF NOT EXISTS evaluations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id INTEGER NOT NULL,
                student_id INTEGER NOT NULL,
                cycle_id INTEGER NOT NULL,
                score TEXT NULL,
                selected_at TEXT NOT NULL,
                evaluated_by INTEGER NULL,
                evaluated_at TEXT NULL,
                UNIQUE(cycle_id, student_id),
                FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE,
                FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,
                FOREIGN KEY(cycle_id) REFERENCES evaluation_cycles(id) ON DELETE CASCADE,
                FOREIGN KEY(evaluated_by) REFERENCES users(id) ON DELETE SET NULL
            )',
            'CREATE TABLE IF NOT EXISTS attendance_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id INTEGER NOT NULL,
                student_id INTEGER NOT NULL,
                attendance_date TEXT NOT NULL,
                attendance_score INTEGER NOT NULL DEFAULT 0,
                marked_by INTEGER NULL,
                marked_at TEXT NOT NULL,
                UNIQUE(class_id, student_id, attendance_date),
                FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE,
                FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,
                FOREIGN KEY(marked_by) REFERENCES users(id) ON DELETE SET NULL
            )',
            'CREATE TABLE IF NOT EXISTS imports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id INTEGER NOT NULL,
                file_name TEXT NOT NULL,
                imported_at TEXT NOT NULL,
                FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE
            )',
            'CREATE TABLE IF NOT EXISTS user_invites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT NOT NULL UNIQUE,
                created_by INTEGER NOT NULL,
                accepted_user_id INTEGER NULL,
                created_at TEXT NOT NULL,
                accepted_at TEXT NULL,
                FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(accepted_user_id) REFERENCES users(id) ON DELETE SET NULL
            )',
        ];
    }

    private static function mysqlSchema(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(120) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                is_admin TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS classes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(120) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                term VARCHAR(120) NOT NULL,
                year INT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                owner_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS students (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_code VARCHAR(120) NOT NULL UNIQUE,
                display_name VARCHAR(255) NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS class_students (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                class_id INT UNSIGNED NOT NULL,
                student_id INT UNSIGNED NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_class_student (class_id, student_id),
                CONSTRAINT fk_class_students_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
                CONSTRAINT fk_class_students_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS evaluation_cycles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                class_id INT UNSIGNED NOT NULL,
                cycle_number INT NOT NULL,
                status VARCHAR(20) NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                UNIQUE KEY uniq_cycle_number (class_id, cycle_number),
                CONSTRAINT fk_cycles_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS evaluations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                class_id INT UNSIGNED NOT NULL,
                student_id INT UNSIGNED NOT NULL,
                cycle_id INT UNSIGNED NOT NULL,
                score CHAR(1) NULL,
                selected_at DATETIME NOT NULL,
                evaluated_by INT UNSIGNED NULL,
                evaluated_at DATETIME NULL,
                UNIQUE KEY uniq_cycle_student (cycle_id, student_id),
                CONSTRAINT fk_evaluations_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
                CONSTRAINT fk_evaluations_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
                CONSTRAINT fk_evaluations_cycle FOREIGN KEY (cycle_id) REFERENCES evaluation_cycles(id) ON DELETE CASCADE,
                CONSTRAINT fk_evaluations_user FOREIGN KEY (evaluated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS attendance_records (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                class_id INT UNSIGNED NOT NULL,
                student_id INT UNSIGNED NOT NULL,
                attendance_date DATE NOT NULL,
                attendance_score TINYINT NOT NULL DEFAULT 0,
                marked_by INT UNSIGNED NULL,
                marked_at DATETIME NOT NULL,
                UNIQUE KEY uniq_attendance_day (class_id, student_id, attendance_date),
                CONSTRAINT fk_attendance_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
                CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
                CONSTRAINT fk_attendance_marked_by FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS imports (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                class_id INT UNSIGNED NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                imported_at DATETIME NOT NULL,
                CONSTRAINT fk_imports_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS user_invites (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                token VARCHAR(120) NOT NULL UNIQUE,
                created_by INT UNSIGNED NOT NULL,
                accepted_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                accepted_at DATETIME NULL,
                CONSTRAINT fk_user_invites_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_invites_accepted_user FOREIGN KEY (accepted_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }

    private static function ensureAdditionalColumns(PDO $pdo, string $driver): void
    {
        if (!self::columnExists($pdo, 'users', 'is_admin', $driver)) {
            $pdo->exec(
                $driver === 'mysql'
                    ? 'ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0'
                    : 'ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0'
            );
        }

        if (!self::columnExists($pdo, 'classes', 'owner_user_id', $driver)) {
            $pdo->exec(
                $driver === 'mysql'
                    ? 'ALTER TABLE classes ADD COLUMN owner_user_id INT UNSIGNED NULL'
                    : 'ALTER TABLE classes ADD COLUMN owner_user_id INTEGER NULL'
            );
        }

        if (self::tableExists($pdo, 'attendance_records', $driver) && !self::columnExists($pdo, 'attendance_records', 'attendance_score', $driver)) {
            $pdo->exec(
                $driver === 'mysql'
                    ? 'ALTER TABLE attendance_records ADD COLUMN attendance_score TINYINT NOT NULL DEFAULT 0'
                    : 'ALTER TABLE attendance_records ADD COLUMN attendance_score INTEGER NOT NULL DEFAULT 0'
            );

            if (self::columnExists($pdo, 'attendance_records', 'is_present', $driver)) {
                $pdo->exec('UPDATE attendance_records SET attendance_score = CASE WHEN is_present > 0 THEN 2 ELSE 0 END');
            }
        }
    }

    private static function tableExists(PDO $pdo, string $table, string $driver): bool
    {
        if ($driver === 'mysql') {
            $statement = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
            return $statement !== false && $statement->fetch(PDO::FETCH_NUM) !== false;
        }

        $statement = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $statement->execute(['table' => $table]);
        return $statement->fetchColumn() !== false;
    }

    private static function backfillAdminUser(PDO $pdo): void
    {
        $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
        if ($adminCount > 0) {
            return;
        }

        $firstUserId = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($firstUserId === false) {
            return;
        }

        $statement = $pdo->prepare('UPDATE users SET is_admin = 1 WHERE id = :id');
        $statement->execute(['id' => (int) $firstUserId]);
    }

    private static function backfillClassOwners(PDO $pdo): void
    {
        $ownerId = $pdo->query('SELECT id FROM users WHERE is_admin = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($ownerId === false) {
            $ownerId = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
        }

        if ($ownerId === false) {
            return;
        }

        $statement = $pdo->prepare('UPDATE classes SET owner_user_id = :owner_user_id WHERE owner_user_id IS NULL');
        $statement->execute(['owner_user_id' => (int) $ownerId]);
    }

    private static function columnExists(PDO $pdo, string $table, string $column, string $driver): bool
    {
        if ($driver === 'mysql') {
            $statement = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($column));
            return $statement !== false && $statement->fetch(PDO::FETCH_ASSOC) !== false;
        }

        $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columns as $definition) {
            if (($definition['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
