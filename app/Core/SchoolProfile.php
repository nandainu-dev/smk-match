<?php
declare(strict_types=1);

namespace App\Core;

final class SchoolProfile
{
    /** @param array<string, string> $branding */
    public function __construct(
        public readonly string $displayName,
        public readonly string $shortName,
        public readonly ?string $tagline,
        public readonly ?string $logoPath,
        public readonly ?string $faviconPath,
        public readonly array $branding,
        public readonly bool $isDevelopmentDefault,
    ) {
    }

    public function logoLabel(): string
    {
        return $this->logoPath === null ? 'Logo fallback active' : 'Logo reference configured';
    }
}
