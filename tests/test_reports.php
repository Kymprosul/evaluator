<?php

declare(strict_types=1);

/**
 * Report editing tests (bulk save safety), on a throwaway SQLite database.
 *
 * Usage: php tests/test_reports.php
 */

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/app/helpers.php';
require_once APP_ROOT . '/app/Database.php';
require_once APP_ROOT . '/app/Repositories/ClassRepository.php';
require_once APP_ROOT . '/app/Services/EvaluationService.php';
require_once APP_ROOT . '/app/Services/ReportService.php';

$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evaluator-reports-test-' . bin2hex(random_bytes(4)) . '.sqlite';
$GLOBALS['app_config'] = ['app' => ['timezone' => 'Asia/Shanghai'], 'db' => ['driver' => 'sqlite', 'database' => $databasePath]];
date_default_timezone_set('Asia/Shanghai');

App\Database::migrate();
$pdo = App\Database::connection();
$now = date('Y-m-d H:i:s');

$pdo->exec("INSERT INTO users (username, password_hash, is_admin, created_at) VALUES ('prof', 'x', 1, '{$now}')");
$userId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO classes (code, name, term, year, is_active, owner_user_id, created_at) VALUES ('c1', 'Clase', 'T', 2026, 1, {$userId}, '{$now}')");
$classId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO classes (code, name, term, year, is_active, owner_user_id, created_at) VALUES ('c2', 'Otra', 'T', 2026, 1, {$userId}, '{$now}')");
$otherClassId = (int) $pdo->lastInsertId();

$studentIds = [];
foreach (['A', 'B', 'C'] as $code) {
    $pdo->exec("INSERT INTO students (student_code, display_name, created_at) VALUES ('{$code}', 'Alumno {$code}', '{$now}')");
    $studentIds[$code] = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO class_students (class_id, student_id, is_active, created_at) VALUES ({$classId}, {$studentIds[$code]}, 1, '{$now}')");
}

$evaluations = new App\Services\EvaluationService();
$reports = new App\Services\ReportService();
$pass = 0;
$fail = 0;

function check(string $name, bool $condition): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . " - {$name}\n";
    $condition ? $pass++ : $fail++;
}

function rejected(callable $callback): bool
{
    try {
        $callback();
        return false;
    } catch (RuntimeException) {
        return true;
    }
}

function score_of(int $studentId): ?string
{
    $value = App\Database::connection()->query("SELECT score FROM evaluations WHERE student_id = {$studentId}")->fetchColumn();
    return $value === false ? 'none' : ($value === null ? null : (string) $value);
}

// A is drawn by the roulette and not scored yet; B is scored.
$evaluations->spin($classId, $userId, true, $studentIds['B'], 'spin_b');
$evaluations->evaluate($classId, 0, '+', $userId, true, 'spin_b', 'eval_b');
$evaluations->spin($classId, $userId, true, $studentIds['A'], 'spin_a');

$reports->upsertScoreByCycle($classId, $studentIds['A'], 1, '', $userId, true);
check('clearing an unscored cell keeps the pending student', score_of($studentIds['A']) === null);

$reports->upsertScoreByCycle($classId, $studentIds['B'], 1, '', $userId, true, '+');
check('clearing a scored cell removes it', score_of($studentIds['B']) === 'none');

$reports->upsertScoreByCycle($classId, $studentIds['C'], 1, '=', $userId, true, '');
check('setting a new score works', score_of($studentIds['C']) === '=');

check('stale page does not overwrite a newer score', rejected(fn() => $reports->upsertScoreByCycle($classId, $studentIds['C'], 1, '-', $userId, true, '+')) && score_of($studentIds['C']) === '=');
check('student outside the class is rejected', rejected(fn() => $reports->upsertScoreByCycle($otherClassId, $studentIds['A'], 1, '+', $userId, true)));

@unlink($databasePath);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
