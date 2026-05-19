<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

App\Auth::requireLogin();

$classId = (int) get_value('id', 0);
$service = new App\Services\EvaluationService();
$currentUserId = (int) App\Auth::id();
$isAdmin = App\Auth::isAdmin();

try {
    $state = $service->state($classId, $currentUserId, $isAdmin);
} catch (Throwable $exception) {
    flash('error', __('not_found'));
    redirect_to(app_url('dashboard.php'));
}

render_page_start(__('classroom'));
?>
<section class="page-heading">
    <div>
        <a class="ghost-link" href="<?= e(app_url('dashboard.php')) ?>">&larr; <?= e(__('back_to_dashboard')) ?></a>
        <h1><?= e($state['class']['name'] ?: $state['class']['code']) ?></h1>
        <p class="muted">
            <?= e((string) $state['class']['students_count']) ?> <?= e(__('students')) ?>
            <?php if ($isAdmin && !empty($state['class']['owner_username'])): ?>
                · <?= e($state['class']['owner_username']) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="badge-group">
        <button type="button" id="reset-button" class="secondary-button"><?= e(__('force_new_round')) ?></button>
        <span class="status-pill"><?= e(__('round')) ?> <?= e((string) $state['cycle']['number']) ?></span>
        <span class="status-pill <?= $state['cycle']['status'] === 'completed' ? 'status-completed' : '' ?>">
            <?= e($state['cycle']['status'] === 'completed' ? 'Completada' : 'Activa') ?>
        </span>
    </div>
</section>

<?php render_flash_messages(); ?>

<section
    id="classroom-app"
    class="classroom-layout"
    data-initial-state="<?= e(json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
    data-spin-url="<?= e(app_url('api/spin.php')) ?>"
    data-evaluate-url="<?= e(app_url('api/evaluate.php')) ?>"
    data-reset-url="<?= e(app_url('api/reset-cycle.php')) ?>"
    data-attendance-url="<?= e(app_url('api/attendance.php')) ?>"
>
    <div class="roulette-panel">
        <div id="roulette-container" class="roulette-wrap">
            <canvas id="roulette-canvas" width="560" height="560"></canvas>
            <div id="winner-display" class="winner-display"></div>
        </div>
        <div class="wheel-actions">
            <button type="button" id="spin-button" class="primary-button"><?= e(__('spin_roulette')) ?></button>
            <span id="eval-sync-indicator" class="sync-dot synced" title="Sync status"></span>
        </div>
        <div class="evaluation-actions">
            <button type="button" class="eval-button eval-positive" data-score="+">+</button>
            <button type="button" class="eval-button eval-neutral" data-score="=">=</button>
            <button type="button" class="eval-button eval-negative" data-score="-">-</button>
            <span id="eval-score-indicator" class="sync-dot synced" title="Sync status"></span>
        </div>
        <div id="classroom-feedback" class="feedback-message"></div>
        <button id="eval-retry-btn" style="display:none" class="secondary-button">Reintentar sync</button>
    </div>

    <aside class="sidebar-panel">
        <div class="info-card stats-grid">
            <div>
                <span><?= e(__('total')) ?></span>
                <strong id="stat-total"><?= e((string) $state['stats']['total_students']) ?></strong>
            </div>
            <div>
                <span><?= e(__('pending')) ?></span>
                <strong id="stat-remaining"><?= e((string) $state['stats']['remaining_students']) ?></strong>
            </div>
            <div>
                <span><?= e(__('evaluated')) ?></span>
                <strong id="stat-evaluated"><?= e((string) $state['stats']['evaluated_students']) ?></strong>
            </div>
        </div>

        <div class="info-card">
            <h2><?= e(__('current_student')) ?></h2>
            <p id="current-student" class="current-student">
                <?= e($state['pending_evaluation']['student']['label'] ?? '...') ?>
            </p>
        </div>

        <div class="info-card">
            <h2><?= e(__('recent_evaluations')) ?></h2>
            <ul id="recent-evaluations" class="recent-list">
                <?php foreach ($state['recent_evaluations'] as $item): ?>
                    <li>
                        <span><?= e($item['student']) ?></span>
                        <strong><?= e($item['score']) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

    </aside>
</section>

<section class="info-card attendance-board">
    <h2><?= e(__('attendance')) ?> <span id="attendance-date" class="attendance-date"></span> <span id="sync-indicator" title="Sync status"></span></h2>
    <div id="attendance-students" class="attendance-grid"></div>
    <button id="retry-sync-btn" style="display:none" class="secondary-button">Reintentar</button>
</section>

<?php $classroomJsVersion = is_file(__DIR__ . '/assets/classroom.js') ? (string) filemtime(__DIR__ . '/assets/classroom.js') : '1'; ?>
<script src="<?= e(app_url('assets/classroom.js?v=' . $classroomJsVersion)) ?>" defer></script>
<?php render_page_end(); ?>
