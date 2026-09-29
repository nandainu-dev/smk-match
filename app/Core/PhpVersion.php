<?php
declare(strict_types=1);

namespace App\Core;

final class PhpVersion
{
    public const MINIMUM_ID = 80300;

    public static function assertSupported(?int $versionId = null): void
    {
        if (($versionId ?? PHP_VERSION_ID) < self::MINIMUM_ID) {
            throw new \RuntimeException('SMK Match requires PHP 8.3 or later.');
        }
    }
}
