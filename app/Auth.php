<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Auth
{
    private static ?array $user = null;

    public static function bootstrap(): void
    {
        if (isset($_SESSION['user_id'])) {
            self::$user = self::findUserById((int) $_SESSION['user_id']);
            if (self::$user === null) {
                self::logout();
            }
        }
    }

    public static function userCount(): int
    {
        $statement = Database::connection()->query('SELECT COUNT(*) FROM users');
        return (int) $statement->fetchColumn();
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::$user['id'] ?? null;
    }

    public static function check(): bool
    {
        return self::$user !== null;
    }

    public static function isAdmin(): bool
    {
        return !empty(self::$user['is_admin']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            if (str_contains(current_path(), '/api/')) {
                json_response([
                    'ok' => false,
                    'message' => 'La sesión ha caducado. Vuelve a iniciar sesión.',
                ], 401);
            }

            flash('error', 'Debes iniciar sesión para continuar.');
            redirect_to(app_url('login.php'));
        }
    }

    public static function requireGuest(): void
    {
        if (self::check()) {
            redirect_to(app_url('dashboard.php'));
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();

        if (!self::isAdmin()) {
            flash('error', 'No tienes permisos para acceder a esta sección.');
            redirect_to(app_url('dashboard.php'));
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::$user = $user;
        return true;
    }

    public static function createInitialUser(string $username, string $password): int
    {
        $username = trim($username);
        if ($username === '') {
            throw new \RuntimeException('El usuario es obligatorio.');
        }

        if (self::usernameExists($username)) {
            throw new \RuntimeException('Ese usuario ya existe.');
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO users (username, password_hash, is_admin, created_at)
             VALUES (:username, :password_hash, :is_admin, :created_at)'
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_admin' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function createInvitedUser(string $username, string $password): int
    {
        $username = trim($username);
        if ($username === '') {
            throw new \RuntimeException('El usuario es obligatorio.');
        }

        if (self::usernameExists($username)) {
            throw new \RuntimeException('Ese usuario ya existe.');
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO users (username, password_hash, is_admin, created_at)
             VALUES (:username, :password_hash, :is_admin, :created_at)'
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_admin' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function logout(): void
    {
        self::$user = null;
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }

    private static function findUserById(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function usernameExists(string $username): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        $statement->execute(['username' => $username]);
        return (int) $statement->fetchColumn() > 0;
    }
}
