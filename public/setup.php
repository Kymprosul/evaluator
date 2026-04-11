<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (App\Auth::userCount() > 0) {
    redirect_to(app_url('login.php'));
}

$error = null;

if (is_post()) {
    verify_csrf();

    $username = trim((string) post_value('username', ''));
    $password = (string) post_value('password', '');
    $passwordConfirmation = (string) post_value('password_confirmation', '');

    if ($username === '' || $password === '') {
        $error = 'Usuario y contraseña son obligatorios.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } else {
        try {
            App\Auth::createInitialUser($username, $password);
            App\Auth::attempt($username, $password);
            flash('success', 'Usuario inicial creado correctamente.');
            redirect_to(app_url('dashboard.php'));
        } catch (Throwable) {
            $error = 'No se pudo crear el usuario inicial.';
        }
    }
}

render_page_start(__('setup'), false);
?>
<section class="auth-layout">
    <div class="auth-card">
        <span class="eyebrow"><?= e(__('setup')) ?></span>
        <h1><?= e(__('admin_user_creation')) ?></h1>
        <p class="muted">El primer usuario se crea aquí y queda guardado con hash seguro.</p>

        <?php if ($error !== null): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="stack-form">
            <?= csrf_field() ?>
            <label>
                <span><?= e(__('username')) ?></span>
                <input type="text" name="username" required autocomplete="username">
            </label>
            <label>
                <span><?= e(__('password')) ?></span>
                <input type="password" name="password" required autocomplete="new-password">
            </label>
            <label>
                <span><?= e(__('password')) ?> (<?= e(__('confirm')) ?>)</span>
                <input type="password" name="password_confirmation" required autocomplete="new-password">
            </label>
            <button type="submit" class="primary-button"><?= e(__('enter')) ?></button>
        </form>
    </div>
</section>
<?php render_page_end(); ?>
