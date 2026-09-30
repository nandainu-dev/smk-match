<?php
declare(strict_types=1);

namespace App\Core;

final class SubmissionOutcome
{
    /** @param list<ResultScore> $scores @param list<int> $tiedProgramIds */
    public function __construct(
        public readonly string $attemptUuid,
        public readonly Result $result,
        public readonly array $scores,
        public readonly array $tiedProgramIds,
        public readonly bool $alreadyCompleted,
    ) {
        if (trim($this->attemptUuid) === '') {
            throw new \InvalidArgumentException('Submission attempt UUID must not be blank.');
        }
    }
}
