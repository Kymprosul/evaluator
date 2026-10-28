<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

App\Auth::requireLogin();

$repository = new App\Repositories\ClassRepository();
$reportService = new App\Services\ReportService();
$currentUserId = (int) App\Auth::id();
$isAdmin = App\Auth::isAdmin();
$classes = $repository->allWithStats($currentUserId, $isAdmin);
$selectedClassId = (int) (post_value('class_id') ?: get_value('class_id', $classes[0]['id'] ?? 0));
$selectedReportType = (string) (post_value('report_type') ?: get_value('report_type', 'exercises'));

if (!in_array($selectedReportType, ['exercises', 'attendance', 'vip'], true)) {
    $selectedReportType = 'exercises';
}

if (is_post()) {
    verify_csrf();

    try {
        $action = (string) post_value('action', '');

        if ($action === 'toggle_vip' && in_array($selectedReportType, ['exercises', 'attendance'], true)) {
            $makeVip = (string) post_value('vip', '1') === '1';
            $reportService->setVip($selectedClassId, (int) post_value('student_id', 0), $makeVip, $currentUserId, $isAdmin);
            flash('success', $makeVip ? 'Alumno marcado como VIP.' : 'VIP eliminado.');
        } elseif ($selectedReportType === 'vip' && $action === 'save_vip') {
            $completed = $reportService->saveVipGaps(
                $selectedClassId,
                (int) post_value('student_id', 0),
                post_value('gap_1', '') === '1',
                post_value('gap_2', '') === '1',
                $currentUserId,
                $isAdmin
            );
            flash('success', $completed ? 'Huecos completados. Ya no es VIP.' : 'Guardado.');
        } elseif ($selectedReportType === 'exercises' && $action === 'save_bulk') {
            $scores = post_value('scores', []);
            $saved = 0;

            foreach (is_array($scores) ? $scores : [] as $studentId => $cycleScores) {
                foreach (is_array($cycleScores) ? $cycleScores : [] as $cycleNumber => $score) {
                    $reportService->upsertScoreByCycle($selectedClassId, (int) $studentId, (int) $cycleNumber, (string) $score, $currentUserId, $isAdmin);
                    $saved++;
                }
            }

            flash('success', $saved > 0 ? 'Cambios guardados.' : 'No había cambios que guardar.');
        } elseif ($selectedReportType === 'attendance' && $action === 'save_attendance_bulk') {
            $cells = post_value('attendance', []);
            $saved = $reportService->saveAttendanceCells($selectedClassId, is_array($cells) ? $cells : [], $currentUserId, $isAdmin);
            flash('success', $saved > 0 ? 'Cambios guardados.' : 'No había cambios que guardar.');
        } elseif ($selectedReportType === 'attendance' && $action === 'add_attendance_date') {
            $reportService->addAttendanceDate($selectedClassId, (string) post_value('attendance_date', ''), $currentUserId, $isAdmin);
            flash('success', 'Columna añadida.');
        } elseif ($selectedReportType === 'attendance' && $action === 'delete_attendance_date') {
            $reportService->deleteAttendanceDate($selectedClassId, (string) post_value('attendance_date', ''), $currentUserId, $isAdmin);
            flash('success', 'Columna eliminada.');
        } elseif ($selectedReportType === 'exercises' && $action === 'add_cycle') {
            $reportService->addCycleColumn($selectedClassId, $currentUserId, $isAdmin);
            flash('success', 'Columna añadida.');
        } elseif ($selectedReportType === 'exercises' && $action === 'delete_cycle') {
            $reportService->deleteCycleColumn($selectedClassId, (int) post_value('cycle_number', 0), $currentUserId, $isAdmin);
            flash('success', 'Columna eliminada.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudieron guardar los cambios.');
    }

    redirect_to(app_url('reports.php?class_id=' . $selectedClassId . '&report_type=' . urlencode($selectedReportType)));
}

if ((string) get_value('export', '') !== '' && $selectedClassId > 0 && $selectedReportType !== 'vip') {
    $matrix = $selectedReportType === 'attendance'
        ? $reportService->attendanceReportMatrix($selectedClassId, $currentUserId, $isAdmin)
        : $reportService->reportMatrix($selectedClassId, $currentUserId, $isAdmin);
    $safeFileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $matrix['class_label']) ?: 'report';
    $exportMode = (string) get_value('export', '');
    $fileSuffix = $selectedReportType === 'attendance' ? '_attendance' : '_exercises';

    if ($exportMode === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeFileName . $fileSuffix . '.csv"');
        echo $selectedReportType === 'attendance'
            ? $reportService->exportAttendanceCsv($selectedClassId, $currentUserId, $isAdmin)
            : $reportService->exportCsv($selectedClassId, $currentUserId, $isAdmin);
        exit;
    }

    if ($exportMode === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeFileName . $fileSuffix . '.xls"');
        echo $selectedReportType === 'attendance'
            ? $reportService->exportAttendanceExcel($selectedClassId, $currentUserId, $isAdmin)
            : $reportService->exportExcel($selectedClassId, $currentUserId, $isAdmin);
        exit;
    }
}

$matrix = null;
if ($selectedClassId > 0) {
    $matrix = match ($selectedReportType) {
        'attendance' => $reportService->attendanceReportMatrix($selectedClassId, $currentUserId, $isAdmin),
        'vip' => $reportService->vipReport($selectedClassId, $currentUserId, $isAdmin),
        default => $reportService->reportMatrix($selectedClassId, $currentUserId, $isAdmin),
    };
}
$vipIds = [];
if ($matrix !== null && $selectedReportType !== 'vip') {
    foreach ($repository->studentsForClass($selectedClassId) as $student) {
        if (!empty($student['is_vip'])) {
            $vipIds[(int) $student['id']] = true;
        }
    }
}

$renderVipToggle = static function (int $studentId, bool $isVip) use ($selectedClassId, $selectedReportType): string {
    return '<form method="post" class="inline-form vip-toggle">' . csrf_field()
        . '<input type="hidden" name="action" value="toggle_vip">'
        . '<input type="hidden" name="class_id" value="' . e((string) $selectedClassId) . '">'
        . '<input type="hidden" name="report_type" value="' . e($selectedReportType) . '">'
        . '<input type="hidden" name="student_id" value="' . $studentId . '">'
        . '<input type="hidden" name="vip" value="' . ($isVip ? '0' : '1') . '">'
        . '<button type="submit" class="vip-button' . ($isVip ? ' is-vip' : '') . '" title="' . e(__($isVip ? 'remove_vip' : 'make_vip')) . '">' . ($isVip ? '★' : '☆') . '</button>'
        . '</form>';
};

render_page_start(__('reports'));
?>
<section class="page-heading">
    <div>
        <span class="eyebrow"><?= e(__('reports')) ?></span>
        <h1><?= e(__('report_by_class')) ?></h1>
    </div>
</section>

<section class="table-card">
    <?php render_flash_messages(); ?>

    <?php if ($classes === []): ?>
        <p class="muted"><?= e(__('no_classes')) ?></p>
    <?php else: ?>
        <form method="get" class="report-selector">
            <label>
                <span><?= e(__('classroom')) ?></span>
                <select name="class_id" required>
                    <?php foreach ($classes as $classroom): ?>
                        <?php $label = $repository->label($classroom); ?>
                        <?php $ownerSuffix = $isAdmin && !empty($classroom['owner_username']) ? ' · ' . $classroom['owner_username'] : ''; ?>
                        <option value="<?= e((string) $classroom['id']) ?>" <?= (int) $classroom['id'] === $selectedClassId ? 'selected' : '' ?>>
                            <?= e($label . $ownerSuffix) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
            <label>
                <span><?= e(__('report_type')) ?></span>
                <select name="report_type" required>
                    <option value="exercises" <?= $selectedReportType === 'exercises' ? 'selected' : '' ?>><?= e(__('report_type_exercises')) ?></option>
                    <option value="attendance" <?= $selectedReportType === 'attendance' ? 'selected' : '' ?>><?= e(__('report_type_attendance')) ?></option>
                    <option value="vip" <?= $selectedReportType === 'vip' ? 'selected' : '' ?>><?= e(__('report_type_vip')) ?></option>
                </select>
            </label>
            <button type="submit" class="primary-button"><?= e(__('run_report')) ?></button>
        </form>

        <?php
        $reportUrl = 'reports.php?class_id=' . $selectedClassId . '&report_type=' . urlencode($selectedReportType);
        $isEditable = $matrix !== null && $selectedReportType !== 'vip';
        $hasColumns = $isEditable && ($selectedReportType === 'exercises' ? $matrix['cycles'] !== [] : $matrix['dates'] !== []);
        $bulkAction = $selectedReportType === 'attendance' ? 'save_attendance_bulk' : 'save_bulk';
        ?>
        <?php if ($isEditable): ?>
            <div class="report-toolbar">
                <div class="report-toolbar-group">
                    <?php if ($hasColumns): ?>
                        <button type="button" class="primary-button" data-edit-toggle data-label-on="<?= e(__('edit')) ?>" data-label-off="<?= e(__('done_editing')) ?>"><?= e(__('edit')) ?></button>
                    <?php endif; ?>
                    <?php if ($selectedReportType === 'exercises'): ?>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_cycle">
                            <input type="hidden" name="class_id" value="<?= e((string) $selectedClassId) ?>">
                            <input type="hidden" name="report_type" value="<?= e($selectedReportType) ?>">
                            <button type="submit" class="secondary-button"><?= e(__('add_column')) ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="inline-form add-date-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_attendance_date">
                            <input type="hidden" name="class_id" value="<?= e((string) $selectedClassId) ?>">
                            <input type="hidden" name="report_type" value="<?= e($selectedReportType) ?>">
                            <input type="date" name="attendance_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required aria-label="<?= e(__('date')) ?>">
                            <button type="submit" class="secondary-button"><?= e(__('add_day')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="report-toolbar-group">
                    <a class="ghost-link" href="<?= e(app_url($reportUrl . '&export=csv')) ?>"><?= e(__('export')) ?> CSV</a>
                    <a class="ghost-link" href="<?= e(app_url($reportUrl . '&export=xls')) ?>"><?= e(__('export')) ?> Excel</a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($classes !== [] && $matrix !== null && $selectedReportType === 'exercises' && $matrix['cycles'] === []): ?>
        <p class="muted"><?= e(__('no_cycles')) ?></p>
    <?php elseif ($classes !== [] && $matrix !== null && $selectedReportType === 'vip'): ?>
        <div class="report-title-row">
            <h2><?= e($matrix['class_label']) ?></h2>
        </div>
        <?php if ($matrix['rows'] === []): ?>
            <p class="muted"><?= e(__('no_vip')) ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?= e(__('student')) ?></th>
                            <th><?= e(__('vip_started')) ?></th>
                            <th><?= e(__('vip_ended')) ?></th>
                            <th><?= e(__('vip_weeks')) ?></th>
                            <th><?= e(__('vip_gap')) ?> 1</th>
                            <th><?= e(__('vip_gap')) ?> 2</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matrix['rows'] as $row): ?>
                            <tr>
                                <td class="vip-name"><?= e($row['student_label']) ?><?= $row['active'] ? '' : ' <span class="muted">(' . e(__('vip_finished')) . ')</span>' ?></td>
                                <td><?= e($row['started_date']) ?></td>
                                <td><?= e($row['ended_date'] ?? '—') ?></td>
                                <td><?= e((string) $row['weeks']) ?></td>
                                <?php foreach ([1, 2] as $slot): ?>
                                    <td><input type="checkbox" class="vip-circle" name="gap_<?= $slot ?>" value="1" <?= $row['gap_' . $slot] ? 'checked' : '' ?> <?= $row['active'] ? '' : 'disabled' ?> form="vip-form-<?= e((string) $row['student_id']) ?>"></td>
                                <?php endforeach; ?>
                                <td>
                                    <form method="post" class="inline-form" id="vip-form-<?= e((string) $row['student_id']) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="save_vip">
                                        <input type="hidden" name="class_id" value="<?= e((string) $selectedClassId) ?>">
                                        <input type="hidden" name="report_type" value="vip">
                                        <input type="hidden" name="student_id" value="<?= e((string) $row['student_id']) ?>">
                                        <?php if ($row['active']): ?>
                                            <button type="submit" class="primary-button"><?= e(__('save')) ?></button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php elseif ($classes !== [] && $matrix !== null && $selectedReportType === 'attendance' && $matrix['dates'] === []): ?>
        <p class="muted"><?= e(__('no_attendance')) ?></p>
    <?php elseif ($classes !== [] && $matrix !== null): ?>
        <div class="report-title-row">
            <h2><?= e($matrix['class_label']) ?></h2>
            <?php if ($isAdmin && !empty($matrix['class']['owner_username'])): ?>
                <p class="muted">Propietario: <?= e($matrix['class']['owner_username']) ?></p>
            <?php endif; ?>
        </div>

        <div class="editable-report" id="report-root" data-editable-report>
            <div class="table-wrap">
                <table class="data-table matrix-table">
                    <thead>
                        <tr>
                            <th><?= e(__('student')) ?></th>
                            <?php if ($selectedReportType === 'exercises'): ?>
                                <?php foreach ($matrix['cycles'] as $cycleNumber): ?>
                                    <th class="matrix-col">
                                        <div class="cycle-header">
                                            <span><?= e(__('score')) ?> <?= e((string) $cycleNumber) ?></span>
                                            <button type="button" class="column-delete"
                                                data-cycle-number="<?= e((string) $cycleNumber) ?>"
                                                data-confirm="<?= e(__('confirm_delete_cycle')) ?>"
                                                title="<?= e(__('delete')) ?>" aria-label="<?= e(__('delete')) ?>">&times;</button>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach ($matrix['dates'] as $attendanceDate): ?>
                                    <th class="matrix-col">
                                        <div class="cycle-header">
                                            <span><?= e($attendanceDate) ?></span>
                                            <button type="button" class="column-delete"
                                                data-attendance-date="<?= e($attendanceDate) ?>"
                                                data-confirm="<?= e(__('confirm_delete_day')) ?>"
                                                title="<?= e(__('delete')) ?>" aria-label="<?= e(__('delete')) ?>">&times;</button>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matrix['rows'] as $row): ?>
                            <?php $studentId = (int) $row['student_id']; ?>
                            <tr>
                                <td><?= $renderVipToggle($studentId, isset($vipIds[$studentId])) ?> <?= e($row['student_label']) ?></td>
                                <?php if ($selectedReportType === 'exercises'): ?>
                                    <?php foreach ($matrix['cycles'] as $cycleNumber): ?>
                                        <?php $current = (string) ($row['cycles'][$cycleNumber]['score'] ?? ''); ?>
                                        <?php $tone = ['+' => 'positive', '=' => 'neutral', '-' => 'negative'][$current] ?? 'empty'; ?>
                                        <td class="matrix-cell">
                                            <span class="cell-view cell-<?= $tone ?>"><?= e($current === '' ? 'NA' : $current) ?></span>
                                            <select class="cell-input" form="report-form" name="scores[<?= $studentId ?>][<?= e((string) $cycleNumber) ?>]" data-initial="<?= e($current) ?>" aria-label="<?= e($row['student_label']) ?> · <?= e(__('score')) ?> <?= e((string) $cycleNumber) ?>">
                                                <option value="" <?= $current === '' ? 'selected' : '' ?>>NA</option>
                                                <?php foreach (['+', '=', '-'] as $scoreOption): ?>
                                                    <option value="<?= e($scoreOption) ?>" <?= $current === $scoreOption ? 'selected' : '' ?>><?= e($scoreOption) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach ($matrix['dates'] as $attendanceDate): ?>
                                        <?php $present = (int) ($row['dates'][$attendanceDate]['attendance_score'] ?? 0) > 0; ?>
                                        <td class="matrix-cell">
                                            <span class="cell-view cell-<?= $present ? 'positive' : 'empty' ?>"><?= $present ? '✓' : '—' ?></span>
                                            <select class="cell-input" form="report-form" name="attendance[<?= $studentId ?>][<?= e($attendanceDate) ?>]" data-initial="<?= $present ? '2' : '0' ?>" aria-label="<?= e($row['student_label']) ?> · <?= e($attendanceDate) ?>">
                                                <option value="2" <?= $present ? 'selected' : '' ?>><?= e(__('present')) ?></option>
                                                <option value="0" <?= $present ? '' : 'selected' ?>><?= e(__('absent')) ?></option>
                                            </select>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" id="report-form" class="save-bar" data-save-bar hidden>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= e($bulkAction) ?>">
                <input type="hidden" name="class_id" value="<?= e((string) $selectedClassId) ?>">
                <input type="hidden" name="report_type" value="<?= e($selectedReportType) ?>">
                <span data-dirty-count data-label="<?= e(__('unsaved_changes')) ?>"></span>
                <div class="button-row">
                    <button type="button" class="ghost-button" data-edit-cancel><?= e(__('cancel')) ?></button>
                    <button type="submit" class="primary-button" data-save-submit><?= e(__('save')) ?></button>
                </div>
            </form>
        </div>

        <form method="post" id="column-delete-form" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $selectedReportType === 'attendance' ? 'delete_attendance_date' : 'delete_cycle' ?>">
            <input type="hidden" name="class_id" value="<?= e((string) $selectedClassId) ?>">
            <input type="hidden" name="report_type" value="<?= e($selectedReportType) ?>">
            <input type="hidden" name="cycle_number" value="">
            <input type="hidden" name="attendance_date" value="">
        </form>
    <?php endif; ?>
</section>
<script src="<?= e(app_url('assets/reports.js?v=' . (is_file(__DIR__ . '/assets/reports.js') ? (string) filemtime(__DIR__ . '/assets/reports.js') : '1'))) ?>" defer></script>
<?php render_page_end(); ?>
