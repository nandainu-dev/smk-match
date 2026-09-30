<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class AdminRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return list<array{id: int, school_id: int, password_hash: string}>
     */
    public function findActiveCredentialsByNormalizedEmail(string $email): array
    {
        $normalizedEmail = self::normalizeEmail($email);
        if ($normalizedEmail === null) {
            return [];
        }

        $statement = $this->connection()->prepare(
            'SELECT id, school_id, password_hash
             FROM admins
             WHERE LOWER(email) = :email
               AND is_active = 1
             ORDER BY id ASC'
        );
        $statement->execute(['email' => $normalizedEmail]);

        $credentials = [];
        foreach ($statement->fetchAll() as $row) {
            $credentials[] = [
                'id' => $this->positiveRowInt($row, 'id'),
                'school_id' => $this->positiveRowInt($row, 'school_id'),
                'password_hash' => $this->nonBlankRowString($row, 'password_hash'),
            ];
        }

        return $credentials;
    }

    private static function normalizeEmail(string $email): ?string
    {
        $normalized = strtolower(trim($email));

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) === false ? null : $normalized;
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }

    /** @param array<string, mixed> $row */
    private function positiveRowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || (int) $row[$key] < 1) {
            throw new RuntimeException('Invalid persisted admin authentication data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function nonBlankRowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key]) || trim($row[$key]) === '') {
            throw new RuntimeException('Invalid persisted admin authentication data.');
        }

        return $row[$key];
    }
}
