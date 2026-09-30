<?php
declare(strict_types=1);

namespace App\Core;

final class ResultScore
{
    public function __construct(
        public readonly int $id,
        public readonly int $resultId,
        public readonly int $programId,
        public readonly float $rawScore,
        public readonly float $normalizedPercentage,
        public readonly string $createdAt,
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        if ($this->id < 1 || $this->resultId < 1 || $this->programId < 1) {
            throw new \InvalidArgumentException('Result score identities must be positive.');
        }

        if (!is_finite($this->rawScore) || !is_finite($this->normalizedPercentage)) {
            throw new \InvalidArgumentException('Result score values must be finite.');
        }

        if (trim($this->createdAt) === '') {
            throw new \InvalidArgumentException('Result score creation timestamp must not be blank.');
        }
    }
}
