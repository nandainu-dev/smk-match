<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class ResultScoreRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(
        int $resultId,
        int $programId,
        float $rawScore,
        float $normalizedPercentage,
        DateTimeImmutable $createdAt,
    ): ResultScore {
        $this->assertPositiveId($resultId, 'Result score result identity');
        $this->assertPositiveId($programId, 'Result score program identity');
        $this->assertFinite($rawScore, 'Result score raw score');
        $this->assertFinite($normalizedPercentage, 'Result score normalized percentage');

        $statement = $this->connection()->prepare(
            'INSERT INTO result_scores (
                result_id,
                program_id,
                raw_score,
                normalized_percentage,
                created_at
            ) VALUES (
                :result_id,
                :program_id,
                :raw_score,
                :normalized_percentage,
                :created_at
            )'
        );
        $statement->execute([
            'result_id' => $resultId,
            'program_id' => $programId,
            'raw_score' => $rawScore,
            'normalized_percentage' => $normalizedPercentage,
            'created_at' => $this->formatUtc($createdAt),
        ]);

        $score = $this->findById((int) $this->connection()->lastInsertId());
        if ($score === null) {
            throw new RuntimeException('Persisted result score could not be reloaded.');
        }

        return $score;
    }

    /** @return list<ResultScore> */
    public function findScoresByResultId(int $resultId): array
    {
        $this->assertPositiveId($resultId, 'Result score result identity');

        $statement = $this->connection()->prepare(
            'SELECT id, result_id, program_id, raw_score, normalized_percentage, created_at
             FROM result_scores
             WHERE result_id = :result_id
             ORDER BY program_id ASC'
        );
        $statement->execute(['result_id' => $resultId]);
        $scores = [];

        foreach ($statement->fetchAll() as $row) {
            $scores[] = $this->hydrate($row);
        }

        return $scores;
    }

    private function findById(int $scoreId): ?ResultScore
    {
        $statement = $this->connection()->prepare(
            'SELECT id, result_id, program_id, raw_score, normalized_percentage, created_at
             FROM result_scores
             WHERE id = :id'
        );
        $statement->execute(['id' => $scoreId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
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
    private function hydrate(array $row): ResultScore
    {
        return new ResultScore(
            $this->rowInt($row, 'id'),
            $this->rowInt($row, 'result_id'),
            $this->rowInt($row, 'program_id'),
            $this->rowFloat($row, 'raw_score'),
            $this->rowFloat($row, 'normalized_percentage'),
            $this->rowString($row, 'created_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted result score data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowFloat(array $row, string $key): float
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || !is_finite((float) $row[$key])) {
            throw new RuntimeException('Invalid persisted result score data.');
        }

        return (float) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key])) {
            throw new RuntimeException('Invalid persisted result score data.');
        }

        return $row[$key];
    }
}
