<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class MonitorIdentityRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array{footer_logo_path: ?string, footer_text: ?string} */
    public function forSchool(int $schoolId): array
    {
        $this->assertSchoolId($schoolId);
        $statement = $this->connection()->prepare(
            'SELECT footer_logo_path, footer_text
             FROM monitor_settings
             WHERE school_id = :school_id'
        );
        $statement->execute(['school_id' => $schoolId]);
        $row = $statement->fetch();

        return $row === false
            ? ['footer_logo_path' => null, 'footer_text' => null]
            : [
                'footer_logo_path' => $this->nullableString($row, 'footer_logo_path'),
                'footer_text' => $this->nullableString($row, 'footer_text'),
            ];
    }

    /** @return array{footer_logo_path: ?string, footer_text: ?string} */
    public function saveForSchool(int $schoolId, ?string $logoPath, ?string $footerText): array
    {
        $this->assertSchoolId($schoolId);
        if ($logoPath !== null && !ProgramMediaStorage::isCanonicalPublicPath($logoPath)) {
            throw new \InvalidArgumentException('Footer logo path must be a canonical local upload path.');
        }

        $statement = $this->connection()->prepare(
            'INSERT INTO monitor_settings (
                school_id, campaign_id, rotation_seconds, polling_seconds, is_active,
                footer_logo_path, footer_text, updated_at
             ) VALUES (
                :school_id, NULL, 5, 10, 0, :footer_logo_path, :footer_text, UTC_TIMESTAMP()
             ) ON DUPLICATE KEY UPDATE
                footer_logo_path = VALUES(footer_logo_path),
                footer_text = VALUES(footer_text),
                updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            'school_id' => $schoolId,
            'footer_logo_path' => $logoPath,
            'footer_text' => $footerText,
        ]);

        return $this->forSchool($schoolId);
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }

    private function assertSchoolId(int $schoolId): void
    {
        if ($schoolId < 1) {
            throw new \InvalidArgumentException('School identity must be positive.');
        }
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid monitor identity data.');
        }

        return $row[$key];
    }
}
