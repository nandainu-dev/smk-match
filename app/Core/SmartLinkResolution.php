<?php
declare(strict_types=1);

namespace App\Core;

final class SmartLinkResolution
{
    public function __construct(
        public readonly string $alias,
        public readonly int $campaignId,
        public readonly int $activeBatchId,
        public readonly int $quizVersionId,
    ) {
        if (SmartLink::canonicalAlias($this->alias) !== $this->alias) {
            throw new \InvalidArgumentException('Smart link resolution alias must be canonical.');
        }

        foreach ([$this->campaignId, $this->activeBatchId, $this->quizVersionId] as $identity) {
            if ($identity < 1) {
                throw new \InvalidArgumentException('Smart link resolution identities must be positive.');
            }
        }
    }
}
