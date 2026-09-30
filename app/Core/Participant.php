<?php
declare(strict_types=1);

namespace App\Core;

final class Participant
{
    public function __construct(
        public readonly int $id,
        public readonly int $schoolId,
        public readonly string $publicUuid,
        public readonly string $fullName,
        public readonly ?string $originSchool,
        public readonly ?string $className,
        public readonly ?string $phone,
        public readonly bool $marketingConsent,
        public readonly ?string $marketingConsentAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        if ($this->id < 1) {
            throw new \InvalidArgumentException('Participant identity must be positive.');
        }

        if ($this->schoolId < 1) {
            throw new \InvalidArgumentException('Participant school identity must be positive.');
        }

        if (trim($this->publicUuid) === '') {
            throw new \InvalidArgumentException('Participant public UUID must not be blank.');
        }

        if (trim($this->fullName) === '') {
            throw new \InvalidArgumentException('Participant full name must not be blank.');
        }

        if ($this->marketingConsent && $this->marketingConsentAt === null) {
            throw new \InvalidArgumentException('Participant marketing consent timestamp is required when consent is granted.');
        }

        if (!$this->marketingConsent && $this->marketingConsentAt !== null) {
            throw new \InvalidArgumentException('Participant marketing consent timestamp must be null when consent is not granted.');
        }

        if ($this->marketingConsentAt !== null && trim($this->marketingConsentAt) === '') {
            throw new \InvalidArgumentException('Participant marketing consent timestamp must not be blank.');
        }

        if (trim($this->createdAt) === '' || trim($this->updatedAt) === '') {
            throw new \InvalidArgumentException('Participant timestamps must not be blank.');
        }
    }
}
