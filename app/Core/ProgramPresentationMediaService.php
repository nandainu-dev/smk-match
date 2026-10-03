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

    /** @return list<array{id: int, name: string, code: string, mascot_path: ?string}> */
    public function listPrograms(int $schoolId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');

        return $this->programs->listProgramsForSchool($schoolId);
    }

    /** @return array{id: int, name: string, code: string, mascot_path: ?string}|null */
    public function program(int $schoolId, int $programId): ?array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        return $this->programs->findProgramForSchool($schoolId, $programId);
    }

    /** @return array{id: int, name: string, code: string, mascot_path: ?string} */
    public function uploadMascot(int $schoolId, int $programId, string $temporaryPath): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        $publicPath = $this->storage->store($temporaryPath);
        try {
            return $this->programs->recordMascotAndSetCurrent($schoolId, $programId, $publicPath);
        } catch (\Throwable $throwable) {
            $this->storage->discardNewlyStored($publicPath);

            throw $throwable;
        }
    }

    /** @return array{id: int, name: string, code: string, mascot_path: ?string} */
    public function removeMascot(int $schoolId, int $programId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        return $this->programs->clearCurrentMascot($schoolId, $programId);
    }


    /** @return array<string, mixed> */
    public function savePresentationContent(
        int $schoolId,
        int $programId,
        string $skills,
        string $careers,
    ): array {
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

            if (
                strlen($line) > 255
                || str_contains($line, "\0")
                || str_contains($line, '<')
                || str_contains($line, '>')
            ) {
                throw new \InvalidArgumentException(
                    'Program presentation entries must be plain text of at most 255 characters.'
                );
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
