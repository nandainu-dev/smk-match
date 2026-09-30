<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class ResultRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(
        int $attemptId,
        float $totalRawScore,
        ?int $dominantProgramId,
        bool $isTie,
        DateTimeImmutable $createdAt,
    ): Result {
        $this->assertPositiveId($attemptId, 'Result attempt identity');
        $this->assertFinite($totalRawScore, 'Result total raw score');

        if ($dominantProgramId !== null) {
            $this->assertPositiveId($dominantProgramId, 'Result dominant program identity');
        }

        if ($isTie && $dominantProgramId !== null) {
            throw new \InvalidArgumentException('Tied results must not have a dominant program.');
        }

        $statement = $this->connection()->prepare(
            'INSERT INTO results (attempt_id, total_raw_score, dominant_program_id, is_tie, created_at)
             VALUES (:attempt_id, :total_raw_score, :dominant_program_id, :is_tie, :created_at)'
        );
        $statement->execute([
            'attempt_id' => $attemptId,
            'total_raw_score' => $totalRawScore,
            'dominant_program_id' => $dominantProgramId,
            'is_tie' => $isTie ? 1 : 0,
            'created_at' => $this->formatUtc($createdAt),
        ]);

        $result = $this->findById((int) $this->connection()->lastInsertId());
        if ($result === null) {
            throw new RuntimeException('Persisted result could not be reloaded.');
        }

        return $result;
    }

    public function findById(int $resultId): ?Result
    {
        $this->assertPositiveId($resultId, 'Result identity');

        return $this->findByColumn('id', $resultId);
    }

    public function findByAttemptId(int $attemptId): ?Result
    {
        $this->assertPositiveId($attemptId, 'Result attempt identity');

        return $this->findByColumn('attempt_id', $attemptId);
    }

    private function findByColumn(string $column, int $value): ?Result
    {
        $statement = $this->connection()->prepare(
            $this->selectSql() . ' WHERE ' . $column . ' = :value'
        );
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
        return 'SELECT id, attempt_id, total_raw_score, dominant_program_id, is_tie, created_at
                FROM results';
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }

    private function assertFinite(float $value, string $label): void
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException($label . ' must be finite.');
        }
    }

    private function formatUtc(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Result
    {
        return new Result(
            $this->rowInt($row, 'id'),
            $this->rowInt($row, 'attempt_id'),
            $this->rowFloat($row, 'total_raw_score'),
            $this->rowNullableInt($row, 'dominant_program_id'),
            $this->rowBool($row, 'is_tie'),
            $this->rowString($row, 'created_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted result data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullableInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('Invalid persisted result data.');
        }

        if ($row[$key] === null) {
            return null;
        }

        if (!is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted result data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowFloat(array $row, string $key): float
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || !is_finite((float) $row[$key])) {
            throw new RuntimeException('Invalid persisted result data.');
        }

        return (float) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowBool(array $row, string $key): bool
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted result data.');
        }

        $value = (int) $row[$key];
        if ($value !== 0 && $value !== 1) {
            throw new RuntimeException('Invalid persisted result data.');
        }

        return $value === 1;
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key])) {
            throw new RuntimeException('Invalid persisted result data.');
        }

        return $row[$key];
    }
}
