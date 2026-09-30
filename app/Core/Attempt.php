<?php
declare(strict_types=1);

namespace App\Core;

final class Attempt
{
    public const STATUS_STARTED = 'started';
    public const STATUS_COMPLETED = 'completed';

    public function __construct(
        public readonly int $id,
        public readonly ?int $participantId,
        public readonly ?int $campaignId,
        public readonly ?int $campaignBatchId,
        public readonly int $quizVersionId,
        public readonly string $visitorUuid,
        public readonly string $attemptUuid,
        public readonly ?string $source,
        public readonly string $status,
        public readonly ?string $submittedAt,
        public readonly string $createdAt,
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        if ($this->id < 1) {
            throw new \InvalidArgumentException('Attempt identity must be positive.');
        }

        foreach ([
            'participant' => $this->participantId,
            'campaign' => $this->campaignId,
            'campaign batch' => $this->campaignBatchId,
        ] as $label => $identity) {
            if ($identity !== null && $identity < 1) {
                throw new \InvalidArgumentException('Attempt ' . $label . ' identity must be positive when present.');
            }
        }

        if ($this->quizVersionId < 1) {
            throw new \InvalidArgumentException('Attempt quiz version identity must be positive.');
        }

        if (trim($this->visitorUuid) === '' || trim($this->attemptUuid) === '') {
            throw new \InvalidArgumentException('Attempt UUIDs must not be blank.');
        }

        if (!in_array($this->status, [self::STATUS_STARTED, self::STATUS_COMPLETED], true)) {
            throw new \InvalidArgumentException('Invalid attempt status.');
        }

        if ($this->status === self::STATUS_STARTED && $this->submittedAt !== null) {
            throw new \InvalidArgumentException('Started attempts must not have a submission timestamp.');
        }

        if ($this->status === self::STATUS_COMPLETED && ($this->submittedAt === null || trim($this->submittedAt) === '')) {
            throw new \InvalidArgumentException('Completed attempts require a submission timestamp.');
        }

        if (trim($this->createdAt) === '') {
            throw new \InvalidArgumentException('Attempt creation timestamp must not be blank.');
        }
    }
}
