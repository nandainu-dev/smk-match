<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class AdminSession
{
    private const SESSION_NAME = 'smk_match_admin';
    private const IDENTITY_KEY = 'admin_identity';
    private const CSRF_KEY = 'admin_csrf_token';

    public function start(bool $isHttps): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start([
            'use_strict_mode' => 1,
            'cookie_httponly' => 1,
            'cookie_secure' => $isHttps ? 1 : 0,
            'cookie_samesite' => 'Lax',
        ]);
    }

    /** @param array{admin_id: int, school_id: int} $identity */
    public function login(array $identity): void
    {
        $this->requireActiveSession();

        if ($identity['admin_id'] < 1 || $identity['school_id'] < 1) {
            throw new \InvalidArgumentException('Admin session identity must be positive.');
        }

        session_regenerate_id(true);
        $_SESSION[self::IDENTITY_KEY] = [
            'admin_id' => $identity['admin_id'],
            'school_id' => $identity['school_id'],
        ];
        unset($_SESSION[self::CSRF_KEY]);
    }

    /** @return array{admin_id: int, school_id: int}|null */
    public function identity(): ?array
    {
        $this->requireActiveSession();

        $identity = $_SESSION[self::IDENTITY_KEY] ?? null;
        if (!is_array($identity)
            || !isset($identity['admin_id'], $identity['school_id'])
            || !is_int($identity['admin_id'])
            || !is_int($identity['school_id'])
            || $identity['admin_id'] < 1
            || $identity['school_id'] < 1) {
            unset($_SESSION[self::IDENTITY_KEY]);

            return null;
        }

        return [
            'admin_id' => $identity['admin_id'],
            'school_id' => $identity['school_id'],
        ];
    }

    public function csrfToken(): string
    {
        $this->requireActiveSession();

        $token = $_SESSION[self::CSRF_KEY] ?? null;
        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1) {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::CSRF_KEY] = $token;

        return $token;
    }

    public function verifyCsrf(?string $submittedToken): bool
    {
        $this->requireActiveSession();

        $token = $_SESSION[self::CSRF_KEY] ?? null;

        return is_string($submittedToken)
            && is_string($token)
            && hash_equals($token, $submittedToken);
    }

    public function logout(): void
    {
        $this->requireActiveSession();

        $_SESSION = [];
        $cookie = session_get_cookie_params();
        if (session_id() !== '') {
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $cookie['path'] ?? '/',
                'domain' => $cookie['domain'] ?? '',
                'secure' => (bool) ($cookie['secure'] ?? false),
                'httponly' => (bool) ($cookie['httponly'] ?? true),
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    private function requireActiveSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Admin session has not been started.');
        }
    }
}
