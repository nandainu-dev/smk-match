<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class SmartLinkRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function findById(int $smartLinkId): ?SmartLink
    {
        $this->assertPositiveId($smartLinkId, 'Smart link identity');

        $statement = $this->connection()->prepare(
            'SELECT id, school_id, campaign_id, name, alias, source, qr_target_url, is_active, scan_count, created_at, updated_at
             FROM smart_links
             WHERE id = :id'
        );
        $statement->execute(['id' => $smartLinkId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByAlias(string $alias): ?SmartLink
    {
        $canonicalAlias = SmartLink::canonicalAlias($alias);

        $statement = $this->connection()->prepare(
            'SELECT id, school_id, campaign_id, name, alias, source, qr_target_url, is_active, scan_count, created_at, updated_at
             FROM smart_links
             WHERE alias = :alias'
        );
        $statement->execute(['alias' => $canonicalAlias]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(
        int $schoolId,
        ?int $campaignId,
        string $name,
        string $alias,
        ?string $source,
        bool $isActive,
    ): SmartLink {
        $this->assertPositiveId($schoolId, 'Smart link school identity');

        if ($campaignId !== null) {
            $this->assertPositiveId($campaignId, 'Smart link campaign identity');
        }

        $canonicalAlias = SmartLink::canonicalAlias($alias);
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Smart link name must not be blank.');
        }

        $statement = $this->connection()->prepare(
            'INSERT INTO smart_links (
                school_id,
                campaign_id,
                name,
                alias,
                source,
                is_active,
                scan_count,
                created_at,
                updated_at
            ) VALUES (
                :school_id,
                :campaign_id,
                :name,
                :alias,
                :source,
                :is_active,
                0,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )'
        );
        $statement->execute([
            'school_id' => $schoolId,
            'campaign_id' => $campaignId,
            'name' => $name,
            'alias' => $canonicalAlias,
            'source' => $source,
            'is_active' => $isActive ? 1 : 0,
        ]);

        $smartLink = $this->findById((int) $this->connection()->lastInsertId());
        if ($smartLink === null) {
            throw new RuntimeException('Persisted smart link could not be reloaded.');
        }

        return $smartLink;
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SmartLink
    {
        return new SmartLink(
            $this->rowInt($row, 'id'),
            $this->rowInt($row, 'school_id'),
            $this->rowNullableInt($row, 'campaign_id'),
            $this->rowString($row, 'name'),
            $this->rowString($row, 'alias'),
            $this->rowNullableString($row, 'source'),
            $this->rowNullableString($row, 'qr_target_url'),
            $this->rowBool($row, 'is_active'),
            $this->rowInt($row, 'scan_count'),
            $this->rowString($row, 'created_at'),
            $this->rowString($row, 'updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted smart link data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullableInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('Invalid persisted smart link data.');
        }

        if ($row[$key] === null) {
            return null;
        }

        if (!is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted smart link data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key])) {
            throw new RuntimeException('Invalid persisted smart link data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid persisted smart link data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowBool(array $row, string $key): bool
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted smart link data.');
        }

        return (int) $row[$key] === 1;
    }
}
