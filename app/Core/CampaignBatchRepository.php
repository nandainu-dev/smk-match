<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PDO;
use RuntimeException;

final class CampaignBatchRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function findById(int $batchId): ?CampaignBatch
    {
        $this->assertPositiveId($batchId, 'Campaign batch identity');

        return $this->find($batchId, false);
    }

    public function findActiveByCampaignId(int $campaignId): ?CampaignBatch
    {
        $this->assertPositiveId($campaignId, 'Campaign identity');

        return $this->findActive($campaignId, false);
    }

    public function findActiveByCampaignIdForUpdate(int $campaignId): ?CampaignBatch
    {
        $this->assertPositiveId($campaignId, 'Campaign identity');
        $this->assertCallerTransaction();

        return $this->findActive($campaignId, true);
    }

    /** @return list<CampaignBatch> */
    public function listByCampaignId(int $campaignId): array
    {
        $this->assertPositiveId($campaignId, 'Campaign identity');

        $statement = $this->connection()->prepare(
            'SELECT id, campaign_id, batch_number, quiz_version_id, label, status, active_marker, started_at, closed_at, created_at, updated_at
             FROM campaign_batches
             WHERE campaign_id = :campaign_id
             ORDER BY batch_number ASC, id ASC'
        );
        $statement->execute(['campaign_id' => $campaignId]);
        $batches = [];

        foreach ($statement->fetchAll() as $row) {
            $batches[] = $this->hydrate($row);
        }

        return $batches;
    }

    public function getMaxBatchNumber(int $campaignId): int
    {
        $this->assertPositiveId($campaignId, 'Campaign identity');

        $statement = $this->connection()->prepare(
            'SELECT COALESCE(MAX(batch_number), 0)
             FROM campaign_batches
             WHERE campaign_id = :campaign_id'
        );
        $statement->execute(['campaign_id' => $campaignId]);

        return (int) $statement->fetchColumn();
    }

    public function create(
        int $campaignId,
        int $batchNumber,
        int $quizVersionId,
        ?string $label,
        string $status,
        ?int $activeMarker,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $closedAt,
    ): CampaignBatch {
        $this->assertPositiveId($campaignId, 'Campaign identity');
        $this->assertPositiveId($batchNumber, 'Campaign batch number');
        $this->assertPositiveId($quizVersionId, 'Quiz version identity');
        $this->assertKnownStatus($status);

        if ($activeMarker !== null && $activeMarker !== 1) {
            throw new \InvalidArgumentException('Campaign batch active marker must be 1 or null.');
        }

        $statement = $this->connection()->prepare(
            'INSERT INTO campaign_batches (
                campaign_id,
                batch_number,
                quiz_version_id,
                label,
                status,
                active_marker,
                started_at,
                closed_at,
                created_at,
                updated_at
            ) VALUES (
                :campaign_id,
                :batch_number,
                :quiz_version_id,
                :label,
                :status,
                :active_marker,
                :started_at,
                :closed_at,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )'
        );
        $statement->execute([
            'campaign_id' => $campaignId,
            'batch_number' => $batchNumber,
            'quiz_version_id' => $quizVersionId,
            'label' => $label,
            'status' => $status,
            'active_marker' => $activeMarker,
            'started_at' => $this->formatUtc($startedAt),
            'closed_at' => $closedAt === null ? null : $this->formatUtc($closedAt),
        ]);

        $batch = $this->findById((int) $this->connection()->lastInsertId());
        if ($batch === null) {
            throw new RuntimeException('Persisted campaign batch could not be reloaded.');
        }

        return $batch;
    }

    public function close(int $batchId, DateTimeImmutable $closedAt): bool
    {
        $this->assertPositiveId($batchId, 'Campaign batch identity');

        $statement = $this->connection()->prepare(
            'UPDATE campaign_batches
             SET status = :closed_status,
                 active_marker = NULL,
                 closed_at = :closed_at,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND status = :active_status
               AND active_marker = 1'
        );
        $statement->execute([
            'id' => $batchId,
            'active_status' => CampaignBatch::STATUS_ACTIVE,
            'closed_status' => CampaignBatch::STATUS_CLOSED,
            'closed_at' => $this->formatUtc($closedAt),
        ]);

        return $statement->rowCount() === 1;
    }

    private function find(int $batchId, bool $forUpdate): ?CampaignBatch
    {
        $sql =
            'SELECT id, campaign_id, batch_number, quiz_version_id, label, status, active_marker, started_at, closed_at, created_at, updated_at
             FROM campaign_batches
             WHERE id = :id';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection()->prepare($sql);
        $statement->execute(['id' => $batchId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    private function findActive(int $campaignId, bool $forUpdate): ?CampaignBatch
    {
        $sql =
            'SELECT id, campaign_id, batch_number, quiz_version_id, label, status, active_marker, started_at, closed_at, created_at, updated_at
             FROM campaign_batches
             WHERE campaign_id = :campaign_id
               AND active_marker = 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection()->prepare($sql);
        $statement->execute(['campaign_id' => $campaignId]);
        $rows = $statement->fetchAll();

        if (count($rows) > 1) {
            throw new RuntimeException('Persistence invariant violation: multiple active campaign batches exist.');
        }

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }

    private function assertCallerTransaction(): void
    {
        if (!$this->connection()->inTransaction()) {
            throw new LogicException('Campaign batch row locking requires a caller-owned transaction.');
        }
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }

    private function assertKnownStatus(string $status): void
    {
        if (!in_array($status, [CampaignBatch::STATUS_ACTIVE, CampaignBatch::STATUS_CLOSED], true)) {
            throw new \InvalidArgumentException('Invalid campaign batch status.');
        }
    }

    private function formatUtc(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CampaignBatch
    {
        return new CampaignBatch(
            $this->rowInt($row, 'id'),
            $this->rowInt($row, 'campaign_id'),
            $this->rowInt($row, 'batch_number'),
            $this->rowInt($row, 'quiz_version_id'),
            $this->rowNullableString($row, 'label'),
            $this->rowString($row, 'status'),
            $this->rowNullableInt($row, 'active_marker'),
            $this->rowString($row, 'started_at'),
            $this->rowNullableString($row, 'closed_at'),
            $this->rowString($row, 'created_at'),
            $this->rowString($row, 'updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted campaign batch data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullableInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('Invalid persisted campaign batch data.');
        }

        if ($row[$key] === null) {
            return null;
        }

        if (!is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted campaign batch data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key])) {
            throw new RuntimeException('Invalid persisted campaign batch data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid persisted campaign batch data.');
        }

        return $row[$key];
    }
}
