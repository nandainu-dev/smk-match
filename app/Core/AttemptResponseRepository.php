<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class AttemptResponseRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(
        int $attemptId,
        int $questionId,
        int $questionOptionId,
        DateTimeImmutable $createdAt,
    ): AttemptResponse {
        $this->assertPositiveId($attemptId, 'Response attempt identity');
        $this->assertPositiveId($questionId, 'Response question identity');
        $this->assertPositiveId($questionOptionId, 'Response question option identity');

        $createdAtString = $this->formatUtc($createdAt);
        $statement = $this->connection()->prepare(
            'INSERT INTO responses (attempt_id, question_id, question_option_id, created_at)
             VALUES (:attempt_id, :question_id, :question_option_id, :created_at)'
        );
        $statement->execute([
            'attempt_id' => $attemptId,
            'question_id' => $questionId,
            'question_option_id' => $questionOptionId,
            'created_at' => $createdAtString,
        ]);

        return new AttemptResponse($attemptId, $questionId, $questionOptionId, $createdAtString);
    }

    /** @return list<AttemptResponse> */
    public function findByAttemptId(int $attemptId): array
    {
        $this->assertPositiveId($attemptId, 'Response attempt identity');

        $statement = $this->connection()->prepare(
            'SELECT attempt_id, question_id, question_option_id, created_at
             FROM responses
             WHERE attempt_id = :attempt_id
             ORDER BY question_id ASC'
        );
        $statement->execute(['attempt_id' => $attemptId]);
        $responses = [];

        foreach ($statement->fetchAll() as $row) {
            $responses[] = $this->hydrate($row);
        }

        return $responses;
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

    private function formatUtc(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AttemptResponse
    {
        return new AttemptResponse(
            $this->rowInt($row, 'attempt_id'),
            $this->rowInt($row, 'question_id'),
            $this->rowInt($row, 'question_option_id'),
            $this->rowString($row, 'created_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted response data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key])) {
            throw new RuntimeException('Invalid persisted response data.');
        }

        return $row[$key];
    }
}
