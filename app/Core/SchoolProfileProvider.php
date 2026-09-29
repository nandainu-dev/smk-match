<?php
declare(strict_types=1);

namespace App\Core;

final class SchoolProfileProvider
{
    public function developmentDefault(): SchoolProfile
    {
        return new SchoolProfile('SMK Assalamah', 'SMK Assalamah', 'SMK Match development profile', null, null, [
            'primary' => '#2D176F',
            'secondary' => '#B9F600',
            'accent' => '#FF4F87',
            'background' => '#FFF9F0',
        ], true);
    }

    public static function color(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : $fallback;
    }
}
