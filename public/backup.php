<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

App\Auth::requireLogin();

$currentUserId = (int) App\Auth::id();
$isAdmin = App\Auth::isAdmin();
$backupService = new App\Services\BackupService();

$preview = null;
$results = null;
$appliedClassId = null;

if (is_post()) {
    verify_csrf();
    $step = (string) post_value('step', '');

    try {
        if ($step === 'preview') {
            $file = $_FILES['backup_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Selecciona el archivo de respaldo.');
            }
            if ((int) $file['size'] > 1024 * 1024) {
                throw new RuntimeException('El archivo es demasiado grande.');
            }

            $backup = $backupService->parse((string) file_get_contents((string) $file['tmp_name']));
            $preview = $backupService->preview($backup, $currentUserId, $isAdmin);
            $_SESSION['backup_upload'] = $backup;
        } elseif ($step === 'apply') {
            $backup = $_SESSION['backup_upload'] ?? null;
            unset($_SESSION['backup_upload']);
            if (!is_array($backup)) {
                throw new RuntimeException('Vuelve a subir el archivo.');
            }

            $results = $backupService->apply($backup, $currentUserId, $isAdmin);
            $appliedClassId = (int) $backup['class_id'];
        }
    } catch (RuntimeException $exception) {
        flash('error', $exception->getMessage());
        redirect_to(app_url('backup.php'));
    }
}

$statusLabels = [
    'applied' => 'Aplicado',
    'duplicate' => 'Ya estaba',
    'skipped' => 'Saltado',
];

render_page_start(__('upload_backup'));
?>
<section class="page-heading">
    <div>
        <a class="ghost-link" href="<?= e(app_url('dashboard.php')) ?>">&larr; <?= e(__('back_to_dashboard')) ?></a>
        <h1><?= e(__('upload_backup')) ?></h1>
        <p class="muted">Sube el archivo que descargaste en la clase cuando no había conexión. Puedes subirlo más de una vez: lo que ya esté en el servidor no se duplica.</p>
    </div>
</section>

<?php render_flash_messages(); ?>

<?php if ($results !== null): ?>
    <section class="info-card">
        <h2>Resultado</h2>
        <div class="table-wrap">
            <table class="data-table backup-table">
                <thead>
                    <tr><th>Acción</th><th>Estado</th><th>Detalle</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr class="backup-row-<?= e($row['status']) ?>">
                            <td><?= e($row['description']) ?></td>
                            <td><strong><?= e($statusLabels[$row['status']]) ?></strong></td>
                            <td><?= e($row['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="backup-actions">
            <a class="primary-button" href="<?= e(app_url('class.php?id=' . $appliedClassId)) ?>">Ir a la clase</a>
            <a class="secondary-button" href="<?= e(app_url('backup.php')) ?>">Subir otro archivo</a>
        </p>
    </section>
<?php elseif ($preview !== null): ?>
    <section class="info-card">
        <h2><?= e($preview['class_label']) ?></h2>
        <p class="muted">Respaldo creado: <?= e($preview['created_at']) ?></p>

        <?php if ($preview['attendance'] !== []): ?>
            <h3>Asistencia</h3>
            <ul class="recent-list">
                <?php foreach ($preview['attendance'] as $day): ?>
                    <li><span><?= e($day['date']) ?></span><strong><?= e((string) $day['present_count']) ?> presentes</strong></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($preview['actions'] !== []): ?>
            <h3>Ruleta</h3>
            <ul class="recent-list">
                <?php foreach ($preview['actions'] as $action): ?>
                    <li><span><?= e($action['description']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($preview['attendance'] === [] && $preview['actions'] === []): ?>
            <p class="muted">El respaldo no contiene nada pendiente.</p>
        <?php endif; ?>

        <form method="post" class="backup-actions">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="apply">
            <button type="submit" class="primary-button">Aplicar respaldo</button>
            <a class="secondary-button" href="<?= e(app_url('backup.php')) ?>">Cancelar</a>
        </form>
    </section>
<?php else: ?>
    <section class="info-card">
        <form method="post" enctype="multipart/form-data" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="preview">
            <label>
                <span>Archivo de respaldo (`.json`)</span>
                <input type="file" name="backup_file" accept=".json,application/json" required>
            </label>
            <button type="submit" class="primary-button">Ver contenido</button>
        </form>
    </section>
<?php endif; ?>

<?php render_page_end(); ?>
