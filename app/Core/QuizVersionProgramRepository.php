<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class QuizVersionProgramRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string, int> */
    public function findProgramIdsByVersionId(int $quizVersionId): array
    {
        if ($quizVersionId < 1) {
            throw new \InvalidArgumentException('Quiz version identity must be positive.');
        }

        $statement = $this->connection()->prepare(
            'SELECT p.id, qvpp.program_code_snapshot
             FROM quiz_version_programs AS qvp
             INNER JOIN quiz_version_program_presentations AS qvpp ON qvpp.quiz_version_program_id = qvp.id
             INNER JOIN programs AS p ON p.id = qvp.program_id
             WHERE qvp.quiz_version_id = :quiz_version_id
             ORDER BY qvpp.program_code_snapshot ASC, p.id ASC'
        );
        $statement->execute(['quiz_version_id' => $quizVersionId]);
        $programIds = [];

        foreach ($statement->fetchAll() as $row) {
            $programCode = $this->rowString($row, 'program_code_snapshot');
            if (isset($programIds[$programCode])) {
                throw new RuntimeException('Persistence invariant violation: duplicate quiz version program code.');
            }

            $programIds[$programCode] = $this->rowInt($row, 'id');
        }

        return $programIds;
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted quiz version program data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key]) || trim($row[$key]) === '') {
            throw new RuntimeException('Invalid persisted quiz version program data.');
        }

        return $row[$key];
    }
}
