<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (App\Auth::userCount() === 0) {
    redirect_to(app_url('setup.php'));
}

App\Auth::requireGuest();

$error = null;

if (is_post()) {
    verify_csrf();

    $username = trim((string) post_value('username', ''));
    $password = (string) post_value('password', '');

    if (!App\Auth::attempt($username, $password)) {
        $error = 'Usuario o contraseña incorrectos.';
    } else {
        flash('success', 'Sesión iniciada.');
        redirect_to(app_url('dashboard.php'));
    }
}

render_page_start(__('login'), false);
?>
<section class="auth-layout">
    <div class="auth-card">
        <span class="eyebrow"><?= e(__('app_name')) ?></span>
        <h1><?= e(__('login')) ?></h1>
        <p class="muted">Acceso al dashboard de evaluación.</p>

        <?php if ($error !== null): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php render_flash_messages(); ?>

        <form method="post" class="stack-form">
            <?= csrf_field() ?>
            <label>
                <span><?= e(__('username')) ?></span>
                <input type="text" name="username" required autocomplete="username">
            </label>
            <label>
                <span><?= e(__('password')) ?></span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button type="submit" class="primary-button"><?= e(__('enter')) ?></button>
        </form>
    </div>
</section>
<?php render_page_end(); ?>
