<?php
declare(strict_types=1);

namespace App\Core;

final class Result
{
    public function __construct(
        public readonly int $id,
        public readonly int $attemptId,
        public readonly float $totalRawScore,
        public readonly ?int $dominantProgramId,
        public readonly bool $isTie,
        public readonly string $createdAt,
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        if ($this->id < 1 || $this->attemptId < 1) {
            throw new \InvalidArgumentException('Result identities must be positive.');
        }

        if (!is_finite($this->totalRawScore)) {
            throw new \InvalidArgumentException('Result total raw score must be finite.');
        }

        if ($this->dominantProgramId !== null && $this->dominantProgramId < 1) {
            throw new \InvalidArgumentException('Result dominant program identity must be positive when present.');
        }

        if ($this->isTie && $this->dominantProgramId !== null) {
            throw new \InvalidArgumentException('Tied results must not have a dominant program.');
        }

        if (trim($this->createdAt) === '') {
            throw new \InvalidArgumentException('Result creation timestamp must not be blank.');
        }
    }
}
