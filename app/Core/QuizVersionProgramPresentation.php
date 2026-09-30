<?php
declare(strict_types=1);

namespace App\Core;

final class QuizVersionProgramPresentation
{
    public function __construct(
        public readonly int $quizVersionProgramId,
        public readonly string $programCodeSnapshot,
        public readonly string $programNameSnapshot,
        public readonly ?string $personalityTitleSnapshot,
        public readonly ?string $mascotPathSnapshot,
        public readonly ?string $primaryColorSnapshot,
        public readonly ?string $accentColorSnapshot,
        public readonly ?string $taglineSnapshot,
        public readonly ?string $descriptionSnapshot,
        public readonly ?string $superpowerSnapshot,
        public readonly ?string $skillsSnapshot,
        public readonly ?string $careersSnapshot,
        public readonly string $snapshotProvenance,
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        if ($this->quizVersionProgramId < 1) {
            throw new \InvalidArgumentException('Quiz version program identity must be positive.');
        }

        $this->assertRequiredPlainText($this->programCodeSnapshot, 'Program code snapshot');
        $this->assertRequiredPlainText($this->programNameSnapshot, 'Program name snapshot');
        $this->assertRequiredPlainText($this->snapshotProvenance, 'Snapshot provenance');
        $this->assertNullablePlainText($this->personalityTitleSnapshot, 'Personality title snapshot');
        $this->assertNullablePlainText($this->taglineSnapshot, 'Tagline snapshot');
        $this->assertNullablePlainText($this->descriptionSnapshot, 'Description snapshot');
        $this->assertNullablePlainText($this->superpowerSnapshot, 'Superpower snapshot');

        if ($this->mascotPathSnapshot !== null
            && (str_contains($this->mascotPathSnapshot, '..')
                || preg_match('#^/assets/[A-Za-z0-9][A-Za-z0-9._/-]*$#', $this->mascotPathSnapshot) !== 1)) {
            throw new \InvalidArgumentException('Mascot path snapshot must be a safe local asset path.');
        }

        foreach ([$this->primaryColorSnapshot, $this->accentColorSnapshot] as $color) {
            if ($color !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
                throw new \InvalidArgumentException('Presentation color snapshots must be #RRGGBB values.');
            }
        }

        $this->assertNullableStringArrayJson($this->skillsSnapshot, 'Skills snapshot');
        $this->assertNullableStringArrayJson($this->careersSnapshot, 'Careers snapshot');
    }

    private function assertRequiredPlainText(string $value, string $label): void
    {
        if (trim($value) === '' || str_contains($value, '<') || str_contains($value, '>')) {
            throw new \InvalidArgumentException($label . ' must be non-blank plain text.');
        }
    }

    private function assertNullablePlainText(?string $value, string $label): void
    {
        if ($value !== null && (str_contains($value, '<') || str_contains($value, '>'))) {
            throw new \InvalidArgumentException($label . ' must be plain text.');
        }
    }

    private function assertNullableStringArrayJson(?string $value, string $label): void
    {
        if ($value === null) {
            return;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException($label . ' must be valid JSON.');
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \InvalidArgumentException($label . ' must be a JSON list of strings.');
        }

        foreach ($decoded as $item) {
            if (!is_string($item) || str_contains($item, '<') || str_contains($item, '>')) {
                throw new \InvalidArgumentException($label . ' must be a JSON list of plain strings.');
            }
        }
    }
}
