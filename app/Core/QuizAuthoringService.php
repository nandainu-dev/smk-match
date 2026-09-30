<?php
declare(strict_types=1);

namespace App\Core;

final class QuizAuthoringService
{
    public function __construct(private readonly QuizVersionRepository $versions)
    {
    }

    public function replaceDraftDefinition(
        int $schoolId,
        int $quizId,
        int $quizVersionId,
        QuizDefinition $definition,
    ): QuizVersion {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($quizId, 'Quiz identity');
        $this->assertPositiveId($quizVersionId, 'Quiz version identity');
        $definition->validate();

        return $this->versions->replaceDraftDefinition(
            $schoolId,
            $quizId,
            $quizVersionId,
            $definition,
        );
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }
}
