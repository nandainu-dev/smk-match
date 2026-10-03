<?php
declare(strict_types=1);

namespace App\Core;

final class MonitorIdentityService
{
    public function __construct(
        private readonly MonitorIdentityRepository $identity,
        private readonly ProgramMediaStorage $storage,
    ) {
    }

    /** @return array{footer_logo_path: ?string, footer_text: ?string} */
    public function forSchool(int $schoolId): array
    {
        return $this->identity->forSchool($schoolId);
    }

    /** @return array{footer_logo_path: ?string, footer_text: ?string} */
    public function save(int $schoolId, ?string $temporaryLogoPath, ?string $footerText): array
    {
        $current = $this->identity->forSchool($schoolId);
        $text = $footerText === null ? null : trim($footerText);
        if ($text !== null && (strlen($text) > 2000 || str_contains($text, "\0") || str_contains($text, '<') || str_contains($text, '>'))) {
            throw new \InvalidArgumentException('Footer text is invalid.');
        }
        if ($text === '') {
            $text = null;
        }

        $logoPath = $current['footer_logo_path'];
        if ($temporaryLogoPath !== null) {
            $logoPath = $this->storage->store($temporaryLogoPath);
        }

        try {
            return $this->identity->saveForSchool($schoolId, $logoPath, $text);
        } catch (\Throwable $throwable) {
            if ($temporaryLogoPath !== null && $logoPath !== $current['footer_logo_path']) {
                $this->storage->discardNewlyStored($logoPath);
            }
            throw $throwable;
        }
    }
}
