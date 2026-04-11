<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$token = trim((string) get_value('token', post_value('token', '')));
$inviteService = new App\Services\InviteService();
$invite = $token !== '' ? $inviteService->findPendingInvite($token) : null;
$error = null;

if (App\Auth::check()) {
    redirect_to(app_url('dashboard.php'));
}

if ($invite === null) {
    $error = 'La invitación no es válida o ya se ha usado.';
}

if (is_post() && $invite !== null) {
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
            $inviteService->acceptInvite($token, $username, $password);
            App\Auth::attempt($username, $password);
            flash('success', 'Acceso creado correctamente.');
            redirect_to(app_url('dashboard.php'));
        } catch (Throwable $exception) {
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo completar el alta.';
        }
    }
}

render_page_start('Invitación', false);
?>
<section class="auth-panel">
    <div class="auth-card">
        <span class="eyebrow">Invitación privada</span>
        <h1>Crear acceso</h1>
        <p class="muted">Define tu usuario y contraseña. Solo verás tus propias clases y reportes.</p>

        <?php if ($error !== null): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($invite !== null): ?>
            <p class="muted">Invitación creada por: <strong><?= e($invite['created_by_username']) ?></strong></p>

            <form method="post" class="stack-form">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label>
                    <span>Usuario</span>
                    <input type="text" name="username" required autocomplete="username">
                </label>
                <label>
                    <span>Contraseña</span>
                    <input type="password" name="password" required autocomplete="new-password">
                </label>
                <label>
                    <span>Repetir contraseña</span>
                    <input type="password" name="password_confirmation" required autocomplete="new-password">
                </label>
                <button type="submit" class="primary-button">Crear acceso</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php render_page_end(); ?>
