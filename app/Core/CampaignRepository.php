<?php
declare(strict_types=1);

namespace App\Core;

use LogicException;
use PDO;
use RuntimeException;

final class CampaignRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function findById(int $campaignId): ?Campaign
    {
        $this->assertPositiveId($campaignId, 'Campaign identity');

        return $this->find($campaignId, false);
    }

    public function findByIdForUpdate(int $campaignId): ?Campaign
    {
        $this->assertPositiveId($campaignId, 'Campaign identity');
        $this->assertCallerTransaction();

        return $this->find($campaignId, true);
    }

    public function updateQuizVersionId(int $campaignId, ?int $quizVersionId): bool
    {
        $this->assertPositiveId($campaignId, 'Campaign identity');

        if ($quizVersionId !== null) {
            $this->assertPositiveId($quizVersionId, 'Quiz version identity');
        }

        $statement = $this->connection()->prepare(
            'UPDATE campaigns
             SET quiz_version_id = :quiz_version_id,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $campaignId,
            'quiz_version_id' => $quizVersionId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function updateStatus(
        int $campaignId,
        string $expectedCurrentStatus,
        string $newStatus,
    ): bool {
        $this->assertPositiveId($campaignId, 'Campaign identity');

        if (trim($expectedCurrentStatus) === '' || trim($newStatus) === '') {
            throw new \InvalidArgumentException('Campaign statuses must not be blank.');
        }

        $statement = $this->connection()->prepare(
            'UPDATE campaigns
             SET status = :new_status,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND status = :expected_status'
        );
        $statement->execute([
            'id' => $campaignId,
            'expected_status' => $expectedCurrentStatus,
            'new_status' => $newStatus,
        ]);

        return $statement->rowCount() === 1;
    }

    private function find(int $campaignId, bool $forUpdate): ?Campaign
    {
        $sql =
            'SELECT id, school_id, quiz_id, quiz_version_id, name, status, starts_at, ends_at, created_at, updated_at
             FROM campaigns
             WHERE id = :id';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection()->prepare($sql);
        $statement->execute(['id' => $campaignId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }

    private function assertCallerTransaction(): void
    {
        if (!$this->connection()->inTransaction()) {
            throw new LogicException('Campaign row locking requires a caller-owned transaction.');
        }
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Campaign
    {
        return new Campaign(
            $this->rowInt($row, 'id'),
            $this->rowInt($row, 'school_id'),
            $this->rowNullableInt($row, 'quiz_id'),
            $this->rowNullableInt($row, 'quiz_version_id'),
            $this->rowString($row, 'name'),
            $this->rowString($row, 'status'),
            $this->rowNullableString($row, 'starts_at'),
            $this->rowNullableString($row, 'ends_at'),
            $this->rowString($row, 'created_at'),
            $this->rowString($row, 'updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted campaign data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullableInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('Invalid persisted campaign data.');
        }

        if ($row[$key] === null) {
            return null;
        }

        if (!is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted campaign data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key])) {
            throw new RuntimeException('Invalid persisted campaign data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid persisted campaign data.');
        }

        return $row[$key];
    }
}
