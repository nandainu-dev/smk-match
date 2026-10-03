<?php
declare(strict_types=1);

namespace App\Core;

final class ProgramPresentationMediaService
{
    public function __construct(
        private readonly AdminProgramMediaRepository $programs,
        private readonly ProgramMediaStorage $storage,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listPrograms(int $schoolId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');

        return $this->programs->listProgramsForSchool($schoolId);
    }

    /** @return array<string, mixed>|null */
    public function program(int $schoolId, int $programId): ?array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        return $this->programs->findProgramForSchool($schoolId, $programId);
    }

    /** @return array<string, mixed> */
    public function uploadMascot(int $schoolId, int $programId, string $temporaryPath): array
    {
        return $this->uploadMedia($schoolId, $programId, 'mascot', $temporaryPath);
    }

    /** @return array<string, mixed> */
    public function uploadMedia(int $schoolId, int $programId, string $role, string $temporaryPath): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        $publicPath = $this->storage->store($temporaryPath);
        try {
            return $this->programs->recordMediaAndSetCurrent($schoolId, $programId, $role, $publicPath);
        } catch (\Throwable $throwable) {
            $this->storage->discardNewlyStored($publicPath);

            throw $throwable;
        }
    }

    /** @return array<string, mixed> */
    public function removeMascot(int $schoolId, int $programId): array
    {
        return $this->removeMedia($schoolId, $programId, 'mascot');
    }

    /** @return array<string, mixed> */
    public function removeMedia(int $schoolId, int $programId, string $role): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        return $this->programs->clearCurrentMedia($schoolId, $programId, $role);
    }

    /** @return array<string, mixed> */
    public function renameProgram(int $schoolId, int $programId, string $name, string $code): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        $name = trim($name);
        $code = strtoupper(trim($code));
        if ($name === '' || strlen($name) > 255 || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Program name is invalid.');
        }
        if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,99}\z/D', $code) !== 1) {
            throw new \InvalidArgumentException('Program code is invalid.');
        }

        return $this->programs->renameProgram($schoolId, $programId, $name, $code);
    }

    /** @return array<string, mixed> */
    public function savePresentationContent(int $schoolId, int $programId, string $skills, string $careers): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        return $this->programs->savePresentationContent(
            $schoolId,
            $programId,
            $this->presentationLines($skills),
            $this->presentationLines($careers),
        );
    }

    /** @return list<string> */
    private function presentationLines(string $value): array
    {
        $items = [];
        foreach (preg_split('/\R/u', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (strlen($line) > 255 || str_contains($line, "\0") || str_contains($line, '<') || str_contains($line, '>')) {
                throw new \InvalidArgumentException('Program presentation entries must be plain text of at most 255 characters.');
            }
            $items[$line] = $line;
        }

        return array_values($items);
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }
}
