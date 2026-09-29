<?php
declare(strict_types=1);

namespace App\Core;

final class QuizVersion
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DISCARDED = 'discarded';

    public function __construct(
        public readonly int $quizId,
        public readonly int $id,
        public readonly int $versionNumber,
        public readonly string $status,
        public readonly string $name,
        private readonly QuizDefinition $quizDefinition,
    ) {
        $this->validate();
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isDiscarded(): bool
    {
        return $this->status === self::STATUS_DISCARDED;
    }

    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    public function definition(): QuizDefinition
    {
        return $this->quizDefinition;
    }

    public function validate(): void
    {
        if ($this->quizId < 1) {
            throw new \InvalidArgumentException('Quiz identity must be positive.');
        }

        if ($this->id < 1) {
            throw new \InvalidArgumentException('Quiz version identity must be positive.');
        }

        if ($this->versionNumber < 1) {
            throw new \InvalidArgumentException('Version number must be positive.');
        }

        if (!in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_PUBLISHED,
            self::STATUS_DISCARDED,
        ], true)) {
            throw new \InvalidArgumentException('Invalid quiz version status.');
        }

        if (trim($this->name) === '') {
            throw new \InvalidArgumentException('Quiz version name must not be blank.');
        }

        $this->quizDefinition->validate();

        if ($this->quizDefinition->version !== $this->versionNumber) {
            throw new \InvalidArgumentException('Quiz definition version must match the quiz version.');
        }

        if ($this->quizDefinition->name !== $this->name) {
            throw new \InvalidArgumentException('Quiz definition name must match the quiz version snapshot.');
        }
    }
}
