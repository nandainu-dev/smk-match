<?php
declare(strict_types=1);

namespace App\Core;

final class AttemptResponse
{
    public function __construct(
        public readonly int $attemptId,
        public readonly int $questionId,
        public readonly int $questionOptionId,
        public readonly string $createdAt,
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        foreach ([
            'attempt' => $this->attemptId,
            'question' => $this->questionId,
            'question option' => $this->questionOptionId,
        ] as $label => $identity) {
            if ($identity < 1) {
                throw new \InvalidArgumentException('Response ' . $label . ' identity must be positive.');
            }
        }

        if (trim($this->createdAt) === '') {
            throw new \InvalidArgumentException('Response creation timestamp must not be blank.');
        }
    }
}
