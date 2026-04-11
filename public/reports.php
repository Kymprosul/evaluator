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

if (is_post()) {
    verify_csrf();

    try {
        $action = (string) post_value('action', '');

        if ($action === 'update_score') {
            $reportService->updateScore((int) post_value('evaluation_id', 0), (string) post_value('score', ''), $currentUserId, $isAdmin);
            flash('success', 'Nota actualizada manualmente.');
        } elseif ($action === 'save_bulk') {
            $scores = post_value('scores', []);

            if (!is_array($scores)) {
                throw new RuntimeException('No se recibieron cambios para guardar.');
            }

            foreach ($scores as $studentId => $cycleScores) {
                if (!is_array($cycleScores)) {
                    continue;
                }

                foreach ($cycleScores as $cycleNumber => $score) {
                    $reportService->upsertScoreByCycle(
                        $selectedClassId,
                        (int) $studentId,
                        (int) $cycleNumber,
                        (string) $score,
                        $currentUserId,
                        $isAdmin
                    );
                }
            }

            flash('success', 'Cambios guardados.');
        } elseif ($action === 'save_cell') {
            $reportService->upsertScoreByCycle(
                $selectedClassId,
                (int) post_value('student_id', 0),
                (int) post_value('cycle_number', 0),
                (string) post_value('score', ''),
                $currentUserId,
                $isAdmin
            );
            flash('success', 'Nota guardada.');
        } elseif ($action === 'add_cycle') {
            $reportService->addCycleColumn($selectedClassId, $currentUserId, $isAdmin);
            flash('success', 'Columna añadida.');
        } elseif ($action === 'delete_cycle') {
            $reportService->deleteCycleColumn($selectedClassId, (int) post_value('cycle_number', 0), $currentUserId, $isAdmin);
            flash('success', 'Columna eliminada.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo actualizar la nota.');
    }

    redirect_to(app_url('reports.php?class_id=' . $selectedClassId));
}

if ((string) get_value('export', '') !== '' && $selectedClassId > 0) {
    $matrix = $reportService->reportMatrix($selectedClassId, $currentUserId, $isAdmin);
    $safeFileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $matrix['class_label']) ?: 'report';
    $exportMode = (string) get_value('export', '');

    if ($exportMode === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeFileName . '.csv"');
        echo $reportService->exportCsv($selectedClassId, $currentUserId, $isAdmin);
        exit;
    }

    if ($exportMode === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeFileName . '.xls"');
        echo $reportService->exportExcel($selectedClassId, $currentUserId, $isAdmin);
        exit;
    }
}

$matrix = $selectedClassId > 0 ? $reportService->reportMatrix($selectedClassId, $currentUserId, $isAdmin) : null;

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
            <button type="submit" class="primary-button"><?= e(__('run_report')) ?></button>
        </form>

        <?php if ($matrix !== null): ?>
            <div class="button-row report-actions">
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_cycle">
                    <input type="hidden" name="class_id" value="<?= e((string) $selectedClassId) ?>">
                    <button type="submit" class="secondary-button"><?= e(__('add_column')) ?></button>
                </form>
                <a class="secondary-button" href="<?= e(app_url('reports.php?class_id=' . $selectedClassId . '&export=csv')) ?>"><?= e(__('export')) ?> CSV</a>
                <a class="secondary-button" href="<?= e(app_url('reports.php?class_id=' . $selectedClassId . '&export=xls')) ?>"><?= e(__('export')) ?> Excel</a>
                <form method="post" class="inline-form" id="bulk-report-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_bulk">
                    <input type="hidden" name="class_id" value="<?= e((string) $selectedClassId) ?>">
                    <button type="submit" class="primary-button"><?= e(__('save')) ?> cambios</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($classes !== [] && $matrix !== null && $matrix['cycles'] === []): ?>
        <p class="muted">Esta clase todavía no tiene ciclos evaluados.</p>
    <?php elseif ($classes !== [] && $matrix !== null): ?>
        <div class="report-title-row">
            <h2><?= e($matrix['class_label']) ?></h2>
            <?php if ($isAdmin && !empty($matrix['class']['owner_username'])): ?>
                <p class="muted">Propietario: <?= e($matrix['class']['owner_username']) ?></p>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table class="data-table matrix-table">
                <thead>
                    <tr>
                        <th><?= e(__('student')) ?></th>
                        <?php foreach ($matrix['cycles'] as $cycleNumber): ?>
                            <th>
                                <div class="cycle-header">
                                    <span><?= e(__('score')) ?> <?= e((string) $cycleNumber) ?></span>
                                    <form method="post" onsubmit="return confirm('<?= e(__('confirm_delete_cycle')) ?>');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_cycle">
                                        <input type="hidden" name="class_id" value="<?= e((string) $selectedClassId) ?>">
                                        <input type="hidden" name="cycle_number" value="<?= e((string) $cycleNumber) ?>">
                                        <button type="submit" class="ghost-link danger-link"><?= e(__('delete')) ?></button>
                                    </form>
                                </div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matrix['rows'] as $row): ?>
                        <tr>
                            <td><?= e($row['student_label']) ?></td>
                            <?php foreach ($matrix['cycles'] as $cycleNumber): ?>
                                <td>
                                    <?php $cell = $row['cycles'][$cycleNumber] ?? null; ?>
                                    <select
                                        name="scores[<?= e((string) $row['student_id']) ?>][<?= e((string) $cycleNumber) ?>]"
                                        class="compact-input"
                                        form="bulk-report-form"
                                    >
                                        <option value="" <?= $cell === null ? 'selected' : '' ?>>NA</option>
                                        <?php foreach (['+', '=', '-'] as $scoreOption): ?>
                                            <option value="<?= e($scoreOption) ?>" <?= ($cell['score'] ?? '') === $scoreOption ? 'selected' : '' ?>><?= e($scoreOption) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="button-row report-actions">
            <button type="submit" class="primary-button" form="bulk-report-form"><?= e(__('save')) ?> cambios</button>
        </div>
    <?php endif; ?>
</section>
<?php render_page_end(); ?>
