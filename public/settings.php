<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

App\Auth::requireLogin();

$currentUserId = (int) App\Auth::id();
$isAdmin = App\Auth::isAdmin();
$repository = new App\Repositories\ClassRepository();
$importService = new App\Services\ImportService();
$inviteService = new App\Services\InviteService();

if (is_post()) {
    verify_csrf();
    $action = (string) post_value('action', '');
    $redirectClassId = (int) post_value('selected_class_id', 0);

    try {
        if ($action === 'create_class') {
            $className = trim((string) post_value('class_name', ''));
            if ($className === '') {
                throw new RuntimeException('El nombre de la clase es obligatorio.');
            }

            $redirectClassId = $repository->create([
                'code' => '',
                'name' => $className,
                'term' => 'General',
                'year' => (int) date('Y'),
                'is_active' => 1,
            ], $currentUserId);
            flash('success', 'Clase creada correctamente.');
        } elseif ($action === 'update_class') {
            $classId = (int) post_value('class_id', 0);
            $existing = $repository->find($classId, $currentUserId, $isAdmin);
            if ($existing === null) {
                throw new RuntimeException('Clase no válida.');
            }

            $className = trim((string) post_value('class_name', ''));
            if ($className === '') {
                throw new RuntimeException('El nombre de la clase es obligatorio.');
            }

            $repository->update($classId, [
                'code' => $existing['code'],
                'name' => $className,
                'term' => $existing['term'],
                'year' => $existing['year'],
                'is_active' => post_value('is_active', ''),
            ]);
            $redirectClassId = $classId;
            flash('success', 'Clase actualizada.');
        } elseif ($action === 'delete_class') {
            $classId = (int) post_value('class_id', 0);
            $existing = $repository->find($classId, $currentUserId, $isAdmin);
            if ($existing === null) {
                throw new RuntimeException('Clase no válida.');
            }
            $repository->delete($classId);
            $redirectClassId = 0;
            flash('success', 'Clase eliminada.');
        } elseif ($action === 'import_students') {
            $classId = (int) post_value('class_id', 0);
            $existing = $repository->find($classId, $currentUserId, $isAdmin);
            if ($existing === null) {
                throw new RuntimeException('Clase no válida.');
            }
            $result = $importService->importClassFile($classId, $_FILES['students_file'] ?? []);
            $redirectClassId = $classId;
            flash('success', 'Importación completada: ' . $result['linked_students'] . ' alumnos vinculados.');
        } elseif ($action === 'add_student') {
            $classId = (int) post_value('class_id', 0);
            $existing = $repository->find($classId, $currentUserId, $isAdmin);
            if ($existing === null) {
                throw new RuntimeException('Clase no válida.');
            }
            $repository->addStudentToClass(
                $classId,
                (string) post_value('student_code', ''),
                (string) post_value('display_name', '')
            );
            $redirectClassId = $classId;
            flash('success', 'Alumno añadido a la clase.');
        } elseif ($action === 'remove_student') {
            $classId = (int) post_value('class_id', 0);
            $existing = $repository->find($classId, $currentUserId, $isAdmin);
            if ($existing === null) {
                throw new RuntimeException('Clase no válida.');
            }
            $repository->removeStudentFromClass($classId, (int) post_value('student_id', 0));
            $redirectClassId = $classId;
            flash('success', 'Alumno eliminado de la clase.');
        } elseif ($action === 'create_invite') {
            if (!$isAdmin) {
                throw new RuntimeException('Solo el administrador puede crear invitaciones.');
            }

            $invite = $inviteService->createInvite($currentUserId);
            $_SESSION['last_invite_url'] = $invite['url'];
            flash('success', 'Invitación creada.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo completar la operación.');
    }

    redirect_to(app_url('settings.php' . ($redirectClassId > 0 ? '?class_id=' . $redirectClassId : '')));
}

$classes = $repository->allWithStats($currentUserId, $isAdmin);
$selectedClassId = (int) get_value('class_id', $classes[0]['id'] ?? 0);
$selectedClass = $selectedClassId > 0 ? $repository->find($selectedClassId, $currentUserId, $isAdmin) : null;
$selectedClassId = $selectedClass === null ? (int) ($classes[0]['id'] ?? 0) : $selectedClassId;
$selectedClass = $selectedClassId > 0 ? $repository->find($selectedClassId, $currentUserId, $isAdmin) : null;
$selectedStudents = $selectedClass === null ? [] : $repository->studentsForClass((int) $selectedClass['id']);
$selectedLabel = $selectedClass === null ? null : $repository->label($selectedClass);
$pendingInvites = $isAdmin ? $inviteService->pendingInvites() : [];
$users = $isAdmin ? $inviteService->users() : [];
$lastInviteUrl = $_SESSION['last_invite_url'] ?? null;
unset($_SESSION['last_invite_url']);

render_page_start(__('settings'));
?>
<section class="page-heading">
    <div>
        <span class="eyebrow"><?= e(__('settings')) ?></span>
        <h1><?= e(__('manage_classes')) ?></h1>
    </div>
</section>

<?php render_flash_messages(); ?>

<section class="settings-grid">
    <article class="form-card">
        <h2><?= e(__('create_class')) ?></h2>
        <form method="post" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_class">
            <label>
                <span><?= e(__('class_name')) ?></span>
                <input type="text" name="class_name" required placeholder="ESP 2026 Spring">
            </label>
            <button type="submit" class="primary-button"><?= e(__('create_class')) ?></button>
        </form>
    </article>

    <article class="form-card">
        <h2><?= e(__('select_class')) ?></h2>
        <?php if ($classes === []): ?>
            <p class="muted">Todavía no hay clases creadas.</p>
        <?php else: ?>
            <form method="get" class="stack-form">
                <label>
                    <span>Selecciona la clase</span>
                    <select name="class_id" onchange="this.form.submit()">
                        <?php foreach ($classes as $classroom): ?>
                            <?php $label = $repository->label($classroom); ?>
                            <?php $ownerSuffix = $isAdmin && !empty($classroom['owner_username']) ? ' · ' . $classroom['owner_username'] : ''; ?>
                            <option value="<?= e((string) $classroom['id']) ?>" <?= (int) $classroom['id'] === $selectedClassId ? 'selected' : '' ?>>
                                <?= e($label . $ownerSuffix) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        <?php endif; ?>
    </article>
</section>

<?php if ($selectedClass !== null): ?>
    <section class="table-card">
        <div class="section-heading">
            <h2><?= e($selectedLabel) ?></h2>
            <?php if ($isAdmin && !empty($selectedClass['owner_username'])): ?>
                <span class="muted">Propietario: <?= e($selectedClass['owner_username']) ?></span>
            <?php endif; ?>
            <a class="ghost-link" href="<?= e(app_url('class.php?id=' . (int) $selectedClass['id'])) ?>">Abrir ruleta</a>
        </div>

        <div class="settings-grid single-focus-grid">
            <article class="form-card soft-card">
                <h3>Editar clase</h3>
                <form method="post" class="stack-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_class">
                    <input type="hidden" name="class_id" value="<?= e((string) $selectedClass['id']) ?>">
                    <input type="hidden" name="selected_class_id" value="<?= e((string) $selectedClass['id']) ?>">
                    <label>
                        <span>Nombre de la clase</span>
                        <input type="text" name="class_name" value="<?= e($selectedLabel) ?>" required>
                    </label>
                    <label class="checkbox-field">
                        <input type="checkbox" name="is_active" value="1" <?= !empty($selectedClass['is_active']) ? 'checked' : '' ?>>
                        <span>Clase activa</span>
                    </label>
                    <div class="button-row">
                        <button type="submit" class="secondary-button">Guardar cambios</button>
                    </div>
                </form>

                <form method="post" class="delete-class-form" onsubmit="return confirm('Se eliminará la clase completa y su historial asociado. ¿Continuar?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_class">
                    <input type="hidden" name="class_id" value="<?= e((string) $selectedClass['id']) ?>">
                    <button type="submit" class="danger-button">Eliminar clase</button>
                </form>
            </article>

            <article class="form-card soft-card">
                <h3>Importar alumnos</h3>
                <p class="muted">Un alumno por línea. Formato soportado: <code>codigo</code> o <code>codigo;Nombre Apellido</code>.</p>
                <form method="post" enctype="multipart/form-data" class="stack-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="import_students">
                    <input type="hidden" name="class_id" value="<?= e((string) $selectedClass['id']) ?>">
                    <input type="hidden" name="selected_class_id" value="<?= e((string) $selectedClass['id']) ?>">
                    <label>
                        <span>Archivo `.txt`</span>
                        <input type="file" name="students_file" accept=".txt" required>
                    </label>
                    <button type="submit" class="secondary-button">Importar archivo</button>
                </form>
            </article>
        </div>

        <div class="student-panel no-top-border">
            <div class="section-heading">
                <h3>Alumnos de la clase</h3>
                <span class="muted"><?= e((string) count($selectedStudents)) ?> alumnos</span>
            </div>

            <form method="post" class="student-form single-student-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_student">
                <input type="hidden" name="class_id" value="<?= e((string) $selectedClass['id']) ?>">
                <input type="hidden" name="selected_class_id" value="<?= e((string) $selectedClass['id']) ?>">
                <input type="text" name="student_code" placeholder="ID o codigo del alumno" required>
                <input type="text" name="display_name" placeholder="Nombre del alumno (opcional)">
                <button type="submit" class="secondary-button">Añadir alumno</button>
            </form>

            <?php if ($selectedStudents === []): ?>
                <p class="muted">No hay alumnos en esta clase.</p>
            <?php else: ?>
                <div class="student-list">
                    <?php foreach ($selectedStudents as $student): ?>
                        <div class="student-row">
                            <div>
                                <strong><?= e($student['display_name'] ?: $student['student_code']) ?></strong>
                            </div>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_student">
                                <input type="hidden" name="class_id" value="<?= e((string) $selectedClass['id']) ?>">
                                <input type="hidden" name="selected_class_id" value="<?= e((string) $selectedClass['id']) ?>">
                                <input type="hidden" name="student_id" value="<?= e((string) $student['id']) ?>">
                                <button type="submit" class="danger-button">Eliminar alumno</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($isAdmin): ?>
    <section class="settings-grid">
        <article class="form-card">
            <h2>Invitar usuario</h2>
            <p class="muted">Genera un enlace privado. La otra persona entrará, elegirá usuario y contraseña y solo verá sus propias clases.</p>
            <form method="post" class="stack-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_invite">
                <button type="submit" class="primary-button">Generar invitación</button>
            </form>

            <?php if ($lastInviteUrl !== null): ?>
                <label>
                    <span>Último enlace generado</span>
                    <input type="text" value="<?= e($lastInviteUrl) ?>" readonly>
                </label>
            <?php endif; ?>
        </article>

        <article class="form-card">
            <h2>Usuarios</h2>
            <?php if ($users === []): ?>
                <p class="muted">No hay usuarios registrados.</p>
            <?php else: ?>
                <div class="student-list">
                    <?php foreach ($users as $user): ?>
                        <div class="student-row">
                            <div>
                                <strong><?= e($user['username']) ?></strong>
                                <span class="muted"><?= e($user['is_admin'] ? 'Administrador' : 'Invitado') ?></span>
                            </div>
                            <span class="muted"><?= e($user['created_at']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <section class="table-card">
        <div class="section-heading">
            <h2>Invitaciones pendientes</h2>
            <span class="muted"><?= e((string) count($pendingInvites)) ?> activas</span>
        </div>

        <?php if ($pendingInvites === []): ?>
            <p class="muted">No hay invitaciones pendientes.</p>
        <?php else: ?>
            <div class="student-list">
                <?php foreach ($pendingInvites as $invite): ?>
                    <div class="student-row invite-row">
                        <div class="invite-copy">
                            <strong><?= e($invite['created_by_username']) ?></strong>
                            <span class="muted"><?= e($invite['created_at']) ?></span>
                        </div>
                        <input type="text" value="<?= e($invite['url']) ?>" readonly>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php render_page_end(); ?>
