<?php
declare(strict_types=1);

namespace App\Core;

final class VisitorIdentityCookie
{
    public const NAME = 'smk_match_visitor';
    public const MAX_AGE = 31536000;

    public function __construct(private readonly UuidV4Generator $uuidGenerator)
    {
    }

    /** @return array{visitor_uuid: string, set_cookie: string} */
    public function resolve(Request $request): array
    {
        $visitorUuid = $request->cookie(self::NAME);
        if (!$this->isValidUuidV4($visitorUuid)) {
            $visitorUuid = $this->uuidGenerator->generate();
        }

        return [
            'visitor_uuid' => $visitorUuid,
            'set_cookie' => $this->headerValue($visitorUuid, $request->isHttps),
        ];
    }

    public function isValidUuidV4(?string $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function headerValue(string $visitorUuid, bool $isHttps): string
    {
        $attributes = [
            self::NAME . '=' . $visitorUuid,
            'Max-Age=' . self::MAX_AGE,
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($isHttps) {
            $attributes[] = 'Secure';
        }

        return implode('; ', $attributes);
    }
}
