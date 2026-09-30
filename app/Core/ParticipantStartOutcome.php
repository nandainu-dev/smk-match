<?php
declare(strict_types=1);

namespace App\Core;

final class ParticipantStartOutcome
{
    public function __construct(
        public readonly Participant $participant,
        public readonly Attempt $attempt,
        public readonly CampaignBatch $campaignBatch,
        public readonly int $quizVersionId,
        public readonly bool $alreadyStarted,
    ) {
        if ($this->quizVersionId < 1) {
            throw new \InvalidArgumentException('Start outcome quiz version identity must be positive.');
        }
    }
}
