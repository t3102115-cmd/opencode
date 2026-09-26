<?php

declare(strict_types=1);

final class Auth
{
    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['authenticated']);
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: index.php?page=login');
            exit;
        }
    }

    public static function attemptLogin(string $password): bool
    {
        $hash = (string) Config::get('admin_password_hash');
        if ($hash === '') {
            return false;
        }

        if (password_verify($password, $hash)) {
            $_SESSION['authenticated'] = true;
            session_regenerate_id(true);
            return true;
        }

        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
