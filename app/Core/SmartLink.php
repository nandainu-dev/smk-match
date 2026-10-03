<?php
declare(strict_types=1);

namespace App\Core;

final class SmartLink
{
    public function __construct(
        public readonly int $id,
        public readonly int $schoolId,
        public readonly ?int $campaignId,
        public readonly string $name,
        public readonly string $alias,
        public readonly ?string $source,
        public readonly ?string $qrTargetUrl,
        public readonly bool $isActive,
        public readonly int $scanCount,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        $this->validate();
    }

    public static function canonicalAlias(string $alias): string
    {
        $canonicalAlias = trim($alias);

        if (strlen($canonicalAlias) < 3 || strlen($canonicalAlias) > 80) {
            throw new \InvalidArgumentException('Smart alias must contain between 3 and 80 characters.');
        }

        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $canonicalAlias) !== 1) {
            throw new \InvalidArgumentException('Smart alias must use lowercase ASCII letters, digits, and hyphens only.');
        }

        return $canonicalAlias;
    }

    public function validate(): void
    {
        if ($this->id < 1) {
            throw new \InvalidArgumentException('Smart link identity must be positive.');
        }

        if ($this->schoolId < 1) {
            throw new \InvalidArgumentException('Smart link school identity must be positive.');
        }

        if ($this->campaignId !== null && $this->campaignId < 1) {
            throw new \InvalidArgumentException('Smart link campaign identity must be positive when configured.');
        }

        if (trim($this->name) === '') {
            throw new \InvalidArgumentException('Smart link name must not be blank.');
        }

        if (self::canonicalAlias($this->alias) !== $this->alias) {
            throw new \InvalidArgumentException('Smart link alias must already be canonical.');
        }

        if ($this->qrTargetUrl !== null && trim($this->qrTargetUrl) === '') {
            throw new \InvalidArgumentException('Smart link QR target must not be blank when configured.');
        }

        if ($this->scanCount < 0) {
            throw new \InvalidArgumentException('Smart link scan count must not be negative.');
        }

        if (trim($this->createdAt) === '' || trim($this->updatedAt) === '') {
            throw new \InvalidArgumentException('Smart link timestamps must not be blank.');
        }
    }
}
