<?php
declare(strict_types=1);

namespace App\Core;

final class Campaign
{
    public function __construct(
        public readonly int $id,
        public readonly int $schoolId,
        public readonly ?int $quizId,
        public readonly ?int $quizVersionId,
        public readonly string $name,
        public readonly string $status,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        if ($this->id < 1) {
            throw new \InvalidArgumentException('Campaign identity must be positive.');
        }

        if ($this->schoolId < 1) {
            throw new \InvalidArgumentException('Campaign school identity must be positive.');
        }

        if ($this->quizId !== null && $this->quizId < 1) {
            throw new \InvalidArgumentException('Campaign quiz identity must be positive when configured.');
        }

        if ($this->quizVersionId !== null && $this->quizVersionId < 1) {
            throw new \InvalidArgumentException('Campaign quiz version identity must be positive when configured.');
        }

        if (trim($this->name) === '') {
            throw new \InvalidArgumentException('Campaign name must not be blank.');
        }

        if (trim($this->status) === '') {
            throw new \InvalidArgumentException('Campaign status must not be blank.');
        }

        if (trim($this->createdAt) === '' || trim($this->updatedAt) === '') {
            throw new \InvalidArgumentException('Campaign timestamps must not be blank.');
        }
    }
}
