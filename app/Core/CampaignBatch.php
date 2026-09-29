<?php
declare(strict_types=1);

namespace App\Core;

final class CampaignBatch
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';

    public function __construct(
        public readonly int $id,
        public readonly int $campaignId,
        public readonly int $batchNumber,
        public readonly int $quizVersionId,
        public readonly ?string $label,
        public readonly string $status,
        public readonly ?int $activeMarker,
        public readonly string $startedAt,
        public readonly ?string $closedAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        if ($this->id < 1) {
            throw new \InvalidArgumentException('Campaign batch identity must be positive.');
        }

        if ($this->campaignId < 1) {
            throw new \InvalidArgumentException('Campaign batch campaign identity must be positive.');
        }

        if ($this->batchNumber < 1) {
            throw new \InvalidArgumentException('Campaign batch number must be positive.');
        }

        if ($this->quizVersionId < 1) {
            throw new \InvalidArgumentException('Campaign batch quiz version identity must be positive.');
        }

        if (!in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_CLOSED], true)) {
            throw new \InvalidArgumentException('Invalid campaign batch status.');
        }

        if ($this->activeMarker !== null && $this->activeMarker !== 1) {
            throw new \InvalidArgumentException('Campaign batch active marker must be 1 or null.');
        }

        if (trim($this->startedAt) === '' || trim($this->createdAt) === '' || trim($this->updatedAt) === '') {
            throw new \InvalidArgumentException('Campaign batch required timestamps must not be blank.');
        }
    }
}
