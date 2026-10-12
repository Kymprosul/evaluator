<?php

declare(strict_types=1);

/**
 * Idempotent sync and offline backup tests, on a throwaway SQLite database.
 *
 * Usage: php tests/test_sync.php
 */

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/app/helpers.php';
require_once APP_ROOT . '/app/Database.php';
require_once APP_ROOT . '/app/Repositories/ClassRepository.php';
require_once APP_ROOT . '/app/Services/EvaluationService.php';
require_once APP_ROOT . '/app/Services/BackupService.php';

$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evaluator-sync-test-' . bin2hex(random_bytes(4)) . '.sqlite';
$GLOBALS['app_config'] = ['app' => ['timezone' => 'Asia/Shanghai'], 'db' => ['driver' => 'sqlite', 'database' => $databasePath]];
date_default_timezone_set('Asia/Shanghai');

App\Database::migrate();
$pdo = App\Database::connection();
$now = date('Y-m-d H:i:s');

$pdo->exec("INSERT INTO users (username, password_hash, is_admin, created_at) VALUES ('prof', 'x', 1, '{$now}')");
$userId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO classes (code, name, term, year, is_active, owner_user_id, created_at) VALUES ('c1', 'Clase', 'T', 2026, 1, {$userId}, '{$now}')");
$classId = (int) $pdo->lastInsertId();

$studentIds = [];
foreach (['A', 'B', 'C'] as $code) {
    $pdo->exec("INSERT INTO students (student_code, display_name, created_at) VALUES ('{$code}', 'Alumno {$code}', '{$now}')");
    $studentIds[$code] = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO class_students (class_id, student_id, is_active, created_at) VALUES ({$classId}, {$studentIds[$code]}, 1, '{$now}')");
}

$service = new App\Services\EvaluationService();
$pass = 0;
$fail = 0;

function check(string $name, bool $condition): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . " - {$name}\n";
    $condition ? $pass++ : $fail++;
}

function rejected(callable $callback): ?string
{
    try {
        $callback();
        return null;
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }
}

function count_rows(string $sql): int
{
    return (int) App\Database::connection()->query($sql)->fetchColumn();
}

// --- Spin: a retried request (lost response) is not applied twice ---
$first = $service->spin($classId, $userId, true, $studentIds['A'], 'spin_t1');
$again = $service->spin($classId, $userId, true, $studentIds['A'], 'spin_t1');
check('spin retry is replayed', !empty($again['replayed']));
check('spin retry returns the same evaluation', $again['selected']['id'] === $first['selected']['id']);
check('spin retry creates one row', count_rows('SELECT COUNT(*) FROM evaluations') === 1);

// --- Evaluate by spin token, retried ---
$service->evaluate($classId, 0, '+', $userId, true, 'spin_t1', 'eval_t1');
$evalAgain = $service->evaluate($classId, 0, '+', $userId, true, 'spin_t1', 'eval_t1');
check('evaluate retry is replayed', !empty($evalAgain['replayed']));
check('evaluation scored', count_rows("SELECT COUNT(*) FROM evaluations WHERE score = '+'") === 1);

// --- The server never swaps the student the browser showed ---
$message = rejected(fn() => $service->spin($classId, $userId, true, $studentIds['A'], 'spin_t2'));
check('spin of an already evaluated student is rejected', $message !== null);
check('rejected spin leaves no token behind', count_rows("SELECT COUNT(*) FROM sync_actions WHERE client_token = 'spin_t2'") === 0);

// --- Unknown spin token falls back to the evaluation id ---
$spinB = $service->spin($classId, $userId, true, $studentIds['B'], 'spin_t3');
$service->evaluate($classId, $spinB['selected']['id'], '=', $userId, true, 'spin_missing', 'eval_t3');
check('evaluate falls back to evaluation id', count_rows("SELECT COUNT(*) FROM evaluations WHERE score = '='") === 1);

// --- Last student opens the next round exactly once ---
$cyclesBefore = count_rows('SELECT COUNT(*) FROM evaluation_cycles');
$service->spin($classId, $userId, true, $studentIds['C'], 'spin_t4');
$service->evaluate($classId, 0, '-', $userId, true, 'spin_t4', 'eval_t4');
$service->evaluate($classId, 0, '-', $userId, true, 'spin_t4', 'eval_t4');
check('last evaluation creates one new round', count_rows('SELECT COUNT(*) FROM evaluation_cycles') === $cyclesBefore + 1);

// --- Reset retried ---
$cyclesBefore = count_rows('SELECT COUNT(*) FROM evaluation_cycles');
$service->resetCycle($classId, $userId, true, 'reset_t1');
$service->resetCycle($classId, $userId, true, 'reset_t1');
check('reset retry creates one round', count_rows('SELECT COUNT(*) FROM evaluation_cycles') === $cyclesBefore + 1);

// --- Token reused for another class or type ---
check('token reused for another type is rejected', rejected(fn() => $service->resetCycle($classId, $userId, true, 'spin_t1')) !== null);
check('malformed token is rejected', rejected(fn() => $service->resetCycle($classId, $userId, true, 'bad token!')) !== null);

// --- Attendance ---
$yesterday = date('Y-m-d', strtotime('-1 day'));
$saved = $service->saveAttendance($classId, [$studentIds['A']], $yesterday, $userId, true);
check('attendance for a past day is accepted', $saved['date'] === $yesterday);
check('past attendance stores every student', count_rows("SELECT COUNT(*) FROM attendance_records WHERE attendance_date = '{$yesterday}'") === 3);
check('future attendance is rejected', rejected(fn() => $service->saveAttendance($classId, [], date('Y-m-d', strtotime('+3 days')), $userId, true)) !== null);
check('invalid date is rejected', rejected(fn() => $service->saveAttendance($classId, [], '2026-02-31', $userId, true)) !== null);
$stale = $service->saveAttendance($classId, [], $yesterday, $userId, true, (time() - 3600) * 1000);
check('older backup does not overwrite newer attendance', !empty($stale['skipped']));
check('attendance taken flag', $service->state($classId, $userId, true)['attendance']['taken'] === false);

// --- Backup upload: applied once, then reported as duplicates ---
$backupService = new App\Services\BackupService();
$backupJson = json_encode([
    'format' => 'evaluator-backup',
    'version' => 1,
    'created_at' => date('c'),
    'class' => ['id' => $classId],
    'actions' => [
        ['token' => 'spin_b1', 'type' => 'spin', 'studentId' => $studentIds['B'], 'studentLabel' => 'Alumno B'],
        ['token' => 'eval_b1', 'type' => 'evaluate', 'spinToken' => 'spin_b1', 'studentId' => $studentIds['B'], 'studentLabel' => 'Alumno B', 'score' => '+'],
        ['token' => 'spin_b2', 'type' => 'spin', 'studentId' => $studentIds['B'], 'studentLabel' => 'Alumno B'],
        ['token' => 'eval_b2', 'type' => 'evaluate', 'spinToken' => 'spin_b2', 'studentId' => $studentIds['B'], 'studentLabel' => 'Alumno B', 'score' => '-'],
    ],
    'attendance' => [
        ['date' => date('Y-m-d'), 'ids' => [$studentIds['A'], $studentIds['C']], 'ts' => time() * 1000 + 5000],
    ],
]);
$backup = $backupService->parse($backupJson);
$statuses = array_column($backupService->apply($backup, $userId, true), 'status');
check('backup applies spin, evaluate and attendance', $statuses === ['applied', 'applied', 'skipped', 'skipped', 'applied']);
$statuses = array_column($backupService->apply($backup, $userId, true), 'status');
check('backup uploaded twice changes nothing', array_slice($statuses, 0, 2) === ['duplicate', 'duplicate']);
check('backup did not duplicate the evaluation', count_rows("SELECT COUNT(*) FROM evaluations WHERE student_id = {$studentIds['B']}") === 2);
check('invalid backup file is rejected', rejected(fn() => $backupService->parse('{"format":"other"}')) !== null);

// --- Dashboard order ---
$pdo->exec("INSERT INTO classes (code, name, term, year, is_active, owner_user_id, created_at) VALUES ('c0', 'Aaa', 'T', 2026, 1, {$userId}, '2030-01-01 00:00:00')");
$names = array_column((new App\Repositories\ClassRepository())->allActive($userId, false, true), 'name');
check('dashboard orders classes by creation date', $names === ['Clase', 'Aaa']);

App\Database::connection()->exec('PRAGMA optimize');
@unlink($databasePath);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
