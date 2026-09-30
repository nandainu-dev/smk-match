<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PDO;
use RuntimeException;

final class AttemptRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(
        int $participantId,
        int $campaignId,
        int $campaignBatchId,
        int $quizVersionId,
        string $visitorUuid,
        string $attemptUuid,
        ?string $source,
        DateTimeImmutable $createdAt,
    ): Attempt {
        $this->assertPositiveId($participantId, 'Attempt participant identity');
        $this->assertPositiveId($campaignId, 'Attempt campaign identity');
        $this->assertPositiveId($campaignBatchId, 'Attempt campaign batch identity');
        $this->assertPositiveId($quizVersionId, 'Attempt quiz version identity');
        $this->assertNonBlankString($visitorUuid, 'Attempt visitor UUID');
        $this->assertNonBlankString($attemptUuid, 'Attempt UUID');

        $statement = $this->connection()->prepare(
            'INSERT INTO attempts (
                participant_id,
                campaign_id,
                campaign_batch_id,
                quiz_version_id,
                visitor_uuid,
                attempt_uuid,
                source,
                status,
                submitted_at,
                created_at
            ) VALUES (
                :participant_id,
                :campaign_id,
                :campaign_batch_id,
                :quiz_version_id,
                :visitor_uuid,
                :attempt_uuid,
                :source,
                :status,
                NULL,
                :created_at
            )'
        );
        $statement->execute([
            'participant_id' => $participantId,
            'campaign_id' => $campaignId,
            'campaign_batch_id' => $campaignBatchId,
            'quiz_version_id' => $quizVersionId,
            'visitor_uuid' => $visitorUuid,
            'attempt_uuid' => $attemptUuid,
            'source' => $source,
            'status' => Attempt::STATUS_STARTED,
            'created_at' => $this->formatUtc($createdAt),
        ]);

        $attempt = $this->findById((int) $this->connection()->lastInsertId());
        if ($attempt === null) {
            throw new RuntimeException('Persisted attempt could not be reloaded.');
        }

        return $attempt;
    }

    public function findById(int $attemptId): ?Attempt
    {
        $this->assertPositiveId($attemptId, 'Attempt identity');

        return $this->findByColumn('id', $attemptId, false);
    }

    public function findByAttemptUuid(string $attemptUuid): ?Attempt
    {
        $this->assertNonBlankString($attemptUuid, 'Attempt UUID');

        return $this->findByColumn('attempt_uuid', $attemptUuid, false);
    }

    public function findByAttemptUuidForUpdate(string $attemptUuid): ?Attempt
    {
        $this->assertNonBlankString($attemptUuid, 'Attempt UUID');
        $this->assertCallerTransaction();

        return $this->findByColumn('attempt_uuid', $attemptUuid, true);
    }

    public function markCompleted(int $attemptId, DateTimeImmutable $submittedAt): bool
    {
        $this->assertPositiveId($attemptId, 'Attempt identity');

        $statement = $this->connection()->prepare(
            'UPDATE attempts
             SET status = :completed_status,
                 submitted_at = :submitted_at
             WHERE id = :id
               AND status = :started_status'
        );
        $statement->execute([
            'id' => $attemptId,
            'started_status' => Attempt::STATUS_STARTED,
            'completed_status' => Attempt::STATUS_COMPLETED,
            'submitted_at' => $this->formatUtc($submittedAt),
        ]);

        return $statement->rowCount() === 1;
    }

    private function findByColumn(string $column, int|string $value, bool $forUpdate): ?Attempt
    {
        $sql = $this->selectSql() . ' WHERE ' . $column . ' = :value';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection()->prepare($sql);
        $statement->execute(['value' => $value]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }

    private function selectSql(): string
    {
        return 'SELECT id, participant_id, campaign_id, campaign_batch_id, quiz_version_id,
                       visitor_uuid, attempt_uuid, source, status, submitted_at, created_at
                FROM attempts';
    }

    private function assertCallerTransaction(): void
    {
        if (!$this->connection()->inTransaction()) {
            throw new LogicException('Attempt row locking requires a caller-owned transaction.');
        }
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }

    private function assertNonBlankString(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException($label . ' must not be blank.');
        }
    }

    private function formatUtc(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Attempt
    {
        return new Attempt(
            $this->rowInt($row, 'id'),
            $this->rowNullableInt($row, 'participant_id'),
            $this->rowNullableInt($row, 'campaign_id'),
            $this->rowNullableInt($row, 'campaign_batch_id'),
            $this->rowInt($row, 'quiz_version_id'),
            $this->rowString($row, 'visitor_uuid'),
            $this->rowString($row, 'attempt_uuid'),
            $this->rowNullableString($row, 'source'),
            $this->rowString($row, 'status'),
            $this->rowNullableString($row, 'submitted_at'),
            $this->rowString($row, 'created_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted attempt data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullableInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('Invalid persisted attempt data.');
        }

        if ($row[$key] === null) {
            return null;
        }

        if (!is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted attempt data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key])) {
            throw new RuntimeException('Invalid persisted attempt data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid persisted attempt data.');
        }

        return $row[$key];
    }
}
