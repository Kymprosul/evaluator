<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

App\Auth::requireLogin();

$user = App\Auth::user();
$currentUserId = (int) $user['id'];
$isAdmin = !empty($user['is_admin']);

$repository = new App\Repositories\ClassRepository();
// For the main dashboard, we only show the user's own classes
$myClasses = $repository->allActiveWithStats($currentUserId, false);

render_page_start(__('dashboard'));
?>
<section class="page-heading">
    <div>
        <span class="eyebrow"><?= e(__('dashboard')) ?></span>
        <h1><?= e(__('admin_classes')) ?></h1>
    </div>
    <a class="secondary-button" href="<?= e(app_url('settings.php')) ?>"><?= e(__('settings')) ?></a>
</section>

<?php render_flash_messages(); ?>

<?php if ($myClasses === []): ?>
    <section class="empty-panel">
        <p class="muted"><?= e(__('no_classes')) ?></p>
        <a class="primary-button" href="<?= e(app_url('settings.php')) ?>"><?= e(__('manage_classes')) ?></a>
    </section>
<?php else: ?>
    <section class="card-grid">
        <?php foreach ($myClasses as $classroom): ?>
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
<?php endif; ?>

<?php render_page_end(); ?>
