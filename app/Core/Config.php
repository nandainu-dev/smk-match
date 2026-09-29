<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @param array<string, string> $values */
    private function __construct(private array $values)
    {
    }

    public static function load(string $root): self
    {
        $values = self::readDotEnv($root . '/.env');

        foreach (['APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_TIMEZONE'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $values[$key] = $value;
            }
        }

        $environment = $values['APP_ENV'] ?? 'production';
        $values['APP_NAME'] ??= 'SMK Match';
        $values['APP_ENV'] = $environment;
        $values['APP_DEBUG'] ??= $environment === 'local' ? 'true' : 'false';
        $values['APP_URL'] ??= 'http://127.0.0.1:8080';
        $values['APP_TIMEZONE'] ??= 'Asia/Jakarta';

        return new self($values);
    }

    public function string(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    public function debug(): bool
    {
        return filter_var($this->string('APP_DEBUG'), FILTER_VALIDATE_BOOL);
    }

    /** @return array<string, string> */
    private static function readDotEnv(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '' && preg_match('/^[A-Z0-9_]+$/', $key) === 1) {
                $values[$key] = trim(trim($value), "\"'");
            }
        }

        return $values;
    }
}
