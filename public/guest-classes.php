<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

App\Auth::requireAdmin();

$user = App\Auth::user();
$repository = new App\Repositories\ClassRepository();
$guestClasses = $repository->guestClassesGroupedByUsername();

render_page_start(__('guest_classes'));
?>
<section class="page-heading">
    <div>
        <span class="eyebrow"><?= e(__('users_classes')) ?></span>
        <h1><?= e(__('guest_classes')) ?></h1>
        <p class="muted">Clases organizadas por los usuarios que las han creado.</p>
    </div>
</section>

<?php render_flash_messages(); ?>

<?php if (empty($guestClasses)): ?>
    <section class="empty-panel">
        <p class="muted">No hay clases de invitados todavía.</p>
    </section>
<?php else: ?>
    <?php foreach ($guestClasses as $username => $classes): ?>
        <section class="section-head" style="margin-top: 2rem; margin-bottom: 1rem;">
            <div class="compact-head">
                <span class="eyebrow"><?= e(__('username')) ?></span>
                <h2><?= e($username) ?></h2>
            </div>
        </section>
        <section class="card-grid">
            <?php foreach ($classes as $classroom): ?>
                <?php $classLabel = $repository->label($classroom); ?>
                <a class="class-card" href="<?= e(app_url('class.php?id=' . (int) $classroom['id'])) ?>">
                    <span class="class-icon"><?= e(strtoupper(substr($classLabel, 0, 1))) ?></span>
                    <div class="class-copy">
                        <h2><?= e($classLabel) ?></h2>
                        <p><?= e((string) $classroom['students_count']) ?> <?= e(__('students')) ?></p>
                    </div>
                    <dl class="class-stats">
                        <div>
                            <dt><?= e(__('students')) ?></dt>
                            <dd><?= e((string) $classroom['students_count']) ?></dd>
                        </div>
                        <div>
                            <dt><?= e(__('round')) ?></dt>
                            <dd><?= e((string) (($classroom['latest_cycle']['cycle_number'] ?? 1))) ?></dd>
                        </div>
                    </dl>
                </a>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php render_page_end(); ?>
