<?php
declare(strict_types=1);
namespace App\Core;
final class Program {
    /** @param array<string,string> $colors */
    public function __construct(public readonly string $code, public readonly string $name, public readonly string $personalityTitle, public readonly ?string $mascotPath, public readonly array $colors, public readonly int $order, public readonly bool $active) {}
    public function mascotLabel(): string { return $this->mascotPath === null ? 'Mascot fallback active' : 'Mascot reference configured'; }
}
