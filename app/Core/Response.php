<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string, mixed> $headers */
    public function __construct(public readonly string $body, public readonly int $status = 200, public readonly array $headers = [])
    {
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        return new self((string) json_encode($payload, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
