<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Product-level configuration for the local three-program experience.
 *
 * The generic quiz, scoring, and persistence layers deliberately remain
 * N-program. This boundary is used only by the authenticated admin flow.
 */
final class ThreeProgramQuizConfiguration
{
    public const PROGRAM_COUNT = 3;

    /**
     * @param list<array{id: int, code: string, name: string}> $activePrograms
     * @param list<int> $selectedProgramIds
     * @return array<string, bool>
     */
    public function selectedProgramCodes(array $activePrograms, array $selectedProgramIds): array
    {
        if (count($selectedProgramIds) !== self::PROGRAM_COUNT || count(array_unique($selectedProgramIds, SORT_REGULAR)) !== self::PROGRAM_COUNT) {
            throw new \InvalidArgumentException('Exactly three distinct active programs must be selected.');
        }

        $activeById = [];
        foreach ($activePrograms as $program) {
            if (!isset($program['id'], $program['code'])
                || !is_int($program['id'])
                || $program['id'] < 1
                || !is_string($program['code'])
                || trim($program['code']) === ''
                || isset($activeById[$program['id']])) {
                throw new \RuntimeException('Invalid active program configuration.');
            }

            $activeById[$program['id']] = $program['code'];
        }

        $programs = [];
        foreach ($selectedProgramIds as $programId) {
            if (!is_int($programId) || !isset($activeById[$programId])) {
                throw new \InvalidArgumentException('Selected program is not active in the authenticated school.');
            }

            $code = $activeById[$programId];
            if (isset($programs[$code])) {
                throw new \RuntimeException('Active program codes must be unique in the authenticated school.');
            }
            $programs[$code] = true;
        }

        return $programs;
    }

    public function assertVersion(QuizVersion $version): void
    {
        if (count($version->definition()->programs) !== self::PROGRAM_COUNT) {
            throw new \RuntimeException('This admin flow requires exactly three configured programs.');
        }
    }
}
