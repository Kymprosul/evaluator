<?php

declare(strict_types=1);

/**
 * Seed script for testing the Evaluator project.
 *
 * Creates a test user, a test class, 15 students, and links them together.
 * Idempotent — safe to run multiple times.
 *
 * Usage: php tests/seed_test_data.php
 */

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/app/helpers.php';
require_once APP_ROOT . '/app/Support/Env.php';
require_once APP_ROOT . '/app/Database.php';
require_once APP_ROOT . '/app/Auth.php';

App\Support\Env::load(APP_ROOT . '/.env');
$GLOBALS['app_config'] = require APP_ROOT . '/app/config/app.php';
date_default_timezone_set((string) app_config('app.timezone', 'UTC'));

// Run migrations first
App\Database::migrate();

$pdo = App\Database::connection();
$now = date('Y-m-d H:i:s');

echo "Seeding test data...\n";

// 1. Create test user (idempotent via INSERT OR IGNORE + UPDATE)
$pdo->exec("INSERT OR IGNORE INTO users (username, password_hash, is_admin, created_at)
    VALUES ('testprof', '" . password_hash('test1234', PASSWORD_DEFAULT) . "', 1, '{$now}')");

$stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'testprof' LIMIT 1");
$stmt->execute();
$userId = (int) $stmt->fetchColumn();
echo "  User: testprof (id={$userId})\n";

// 2. Create test class (idempotent)
$pdo->exec("INSERT OR IGNORE INTO classes (code, name, term, year, is_active, owner_user_id, created_at)
    VALUES ('TEST-2026', 'Test Class', 'Spring', 2026, 1, {$userId}, '{$now}')");

$stmt = $pdo->prepare("SELECT id FROM classes WHERE code = 'TEST-2026' LIMIT 1");
$stmt->execute();
$classId = (int) $stmt->fetchColumn();
echo "  Class: TEST-2026 (id={$classId})\n";

// 3. Create 15 students (idempotent)
$studentIds = [];
for ($i = 1; $i <= 15; $i++) {
    $code = sprintf('TEST%03d', $i);
    $name = "Student {$i}";

    $pdo->exec("INSERT OR IGNORE INTO students (student_code, display_name, created_at)
        VALUES ('{$code}', '{$name}', '{$now}')");

    $stmt = $pdo->prepare("SELECT id FROM students WHERE student_code = ? LIMIT 1");
    $stmt->execute([$code]);
    $studentIds[] = (int) $stmt->fetchColumn();
}
echo "  Students: 15 created (ids: " . implode(', ', $studentIds) . ")\n";

// 4. Link students to class (idempotent via UNIQUE constraint)
$insertLink = $pdo->prepare(
    "INSERT OR IGNORE INTO class_students (class_id, student_id, is_active, created_at)
     VALUES (:class_id, :student_id, 1, :created_at)"
);

foreach ($studentIds as $sid) {
    $insertLink->execute([
        'class_id' => $classId,
        'student_id' => $sid,
        'created_at' => $now,
    ]);
}
echo "  Class-Student links: 15 created\n";

echo "Seed complete!\n";
echo "  Login: username=testprof, password=test1234\n";
echo "  Class code: TEST-2026\n";
