<?php

declare(strict_types=1);

function app_config(?string $key = null, mixed $default = null): mixed
{
    $config = $GLOBALS['app_config'] ?? [];
    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function env_value(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function project_path(string $path = ''): string
{
    $root = APP_ROOT;
    return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function storage_path(string $path = ''): string
{
    return project_path($path === '' ? 'storage' : 'storage/' . ltrim($path, '/\\'));
}

function app_url(string $path = ''): string
{
    $baseUrl = trim((string) app_config('app.url', ''));
    $baseUrl = $baseUrl === '' ? '' : '/' . trim($baseUrl, '/');
    $suffix = $path === '' ? '' : '/' . ltrim($path, '/');

    return $baseUrl . $suffix;
}

function app_full_url(string $path = ''): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        return app_url($path);
    }

    return (is_https() ? 'https://' : 'http://') . $host . app_url($path);
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function e(string|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function is_post(): bool
{
    return request_method() === 'POST';
}

function post_value(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

function get_value(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function current_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    return $path === false || $path === null ? '/' : $path;
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string) (post_value('_token', '') ?: ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $sessionToken = (string) ($_SESSION['_csrf_token'] ?? '');

    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        exit('CSRF token invalid.');
    }
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function nav_is_active(string $path): bool
{
    return str_ends_with(current_path(), $path);
}

function send_security_headers(): void
{
    header_remove('X-Powered-By');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function current_lang(): string
{
    return $_SESSION['_lang'] ?? 'es';
}

function set_lang(string $lang): void
{
    if (in_array($lang, ['es', 'en', 'zh'])) {
        $_SESSION['_lang'] = $lang;
    }
}

function __(?string $key = null, mixed $default = null): mixed
{
    static $translations = null;
    $lang = current_lang();

    if ($translations === null) {
        $path = project_path("app/lang/{$lang}.php");
        $translations = file_exists($path) ? require $path : [];
    }

    if ($key === null) {
        return $translations;
    }

    return $translations[$key] ?? ($default ?? $key);
}

function render_page_start(string $title, bool $showNav = true): void
{
    $pageTitle = $title . ' | ' . app_config('app.name', 'Evaluator');
    $currentUser = App\Auth::user();
    $lang = current_lang();
    ?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('assets/app.css')) ?>?v=<?= filemtime(is_file(__DIR__ . '/../public/assets/app.css') ? __DIR__ . '/../public/assets/app.css' : __DIR__ . '/../public_html/assets/app.css') ?>">
</head>
<body>
<div class="app-shell">
    <?php if ($showNav && $currentUser !== null): ?>
        <header class="topbar">
            <div class="brand-block">
                <a class="brand" href="<?= e(app_url('dashboard.php')) ?>">
                    <span class="brand-mark">E</span>
                    <span><?= e(app_config('app.name', 'Evaluator')) ?></span>
                </a>
            </div>
            <nav class="topnav">
                <a class="<?= nav_is_active('/dashboard.php') ? 'is-active' : '' ?>" href="<?= e(app_url('dashboard.php')) ?>"><?= e(__('dashboard')) ?></a>
                <?php if (!empty($currentUser['is_admin'])): ?>
                    <a class="<?= nav_is_active('/guest-classes.php') ? 'is-active' : '' ?>" href="<?= e(app_url('guest-classes.php')) ?>"><?= e(__('guest_classes')) ?></a>
                <?php endif; ?>
                <a class="<?= nav_is_active('/settings.php') ? 'is-active' : '' ?>" href="<?= e(app_url('settings.php')) ?>"><?= e(__('settings')) ?></a>
                <a class="<?= nav_is_active('/reports.php') ? 'is-active' : '' ?>" href="<?= e(app_url('reports.php')) ?>"><?= e(__('reports')) ?></a>
            </nav>
            <div class="topbar-meta">
                <div class="lang-switcher-container" style="position: relative; display: inline-block; margin-right: 0.5rem;">
                    <button type="button" class="ghost-button lang-current-btn" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.9rem; border: 1px solid var(--line); border-radius: 999px; background: rgba(255,255,255,0.6);">
                        <?php if ($lang === 'es'): ?> 🇪🇸 <span class="lang-label">ES</span> <?php elseif ($lang === 'en'): ?> 🇺🇸 <span class="lang-label">EN</span> <?php else: ?> 🇨🇳 <span class="lang-label">Zh</span> <?php endif; ?>
                        <span style="font-size: 0.6rem; opacity: 0.6;">▼</span>
                    </button>
                    <div class="lang-dropdown" style="display: none; position: absolute; top: calc(100% + 5px); right: 0; background: #fff; border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1000; min-width: 140px; overflow: hidden;">
                        <?php 
                        $queryParams = $_GET;
                        $buildLangUrl = function(string $l) use ($queryParams) {
                            $queryParams['lang'] = $l;
                            return '?' . http_build_query($queryParams);
                        };
                        ?>
                        <a href="<?= e($buildLangUrl('es')) ?>" style="display: flex; align-items: center; gap: 0.7rem; padding: 0.8rem 1rem; text-decoration: none; color: var(--text); transition: background 0.2s;">
                            <span style="font-size: 1.2rem;">🇪🇸</span> <span>Español</span>
                        </a>
                        <a href="<?= e($buildLangUrl('en')) ?>" style="display: flex; align-items: center; gap: 0.7rem; padding: 0.8rem 1rem; text-decoration: none; color: var(--text); border-top: 1px solid var(--line); transition: background 0.2s;">
                            <span style="font-size: 1.2rem;">🇺🇸</span> <span>English</span>
                        </a>
                        <a href="<?= e($buildLangUrl('zh')) ?>" style="display: flex; align-items: center; gap: 0.7rem; padding: 0.8rem 1rem; text-decoration: none; color: var(--text); border-top: 1px solid var(--line); transition: background 0.2s;">
                            <span style="font-size: 1.2rem;">🇨🇳</span> <span>中文</span>
                        </a>
                    </div>
                </div>
                <style>
                    .lang-dropdown a:hover { background: var(--bg); }
                    @media (max-width: 600px) { .lang-label { display: none; } }
                </style>
                <script>
                    (function() {
                        const btn = document.querySelector('.lang-current-btn');
                        const dropdown = document.querySelector('.lang-dropdown');
                        if (btn && dropdown) {
                            btn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
                            });
                            document.addEventListener('click', function() {
                                dropdown.style.display = 'none';
                            });
                        }
                    })();
                </script>
                <span class="user-chip"><?= e(!empty($currentUser['is_admin']) ? 'Admin · ' . $currentUser['username'] : $currentUser['username']) ?></span>
                <a class="ghost-link" href="<?= e(app_url('logout.php')) ?>"><?= e(__('logout')) ?></a>
            </div>
        </header>
    <?php endif; ?>
    <main class="page">
    <?php
}

function render_page_end(): void
{
    ?>
    </main>
</div>
</body>
</html>
    <?php
}

function render_flash_messages(): void
{
    foreach (['success', 'error'] as $type) {
        $message = flash($type);
        if ($message === null) {
            continue;
        }
        echo '<div class="flash flash-' . e($type) . '">' . e($message) . '</div>';
    }
}
