<?php
declare(strict_types=1);

namespace App\Core;

final class ErrorHandler
{
    public static function register(bool $debug): void
    {
        set_exception_handler(static function (\Throwable $exception) use ($debug): void {
            error_log((string) $exception);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo $debug ? 'Application error: ' . $exception->getMessage() : 'An unexpected error occurred.';
        });
    }
}
