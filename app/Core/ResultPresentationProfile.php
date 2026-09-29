<?php
declare(strict_types=1);

namespace App\Core;

final class ResultPresentationProfile
{
    /**
     * @param list<string> $skills
     * @param list<string> $careers
     */
    public function __construct(
        public readonly string $code,
        public readonly string $displayName,
        public readonly string $personalityTitle,
        public readonly ?string $mascotPath,
        public readonly string $primaryColor,
        public readonly string $accentColor,
        public readonly string $tagline,
        public readonly string $description,
        public readonly string $superpower,
        public readonly array $skills,
        public readonly array $careers,
        public readonly string $shareHeadline,
    ) {
    }

    /** @return array<string, mixed> */
    public function toSafeArray(bool $includeMascot = false): array
    {
        $profile = [
            'code' => $this->code,
            'display_name' => $this->displayName,
            'personality_title' => $this->personalityTitle,
            'primary_color' => $this->primaryColor,
            'accent_color' => $this->accentColor,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'superpower' => $this->superpower,
            'skills' => $this->skills,
            'careers' => $this->careers,
            'share_headline' => $this->shareHeadline,
        ];

        if ($includeMascot) {
            $profile['mascot_path'] = $this->mascotPath;
        }

        return $profile;
    }
}
