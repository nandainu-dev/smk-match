<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class ResultTiedProgramRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(int $resultId, int $programId): ResultTiedProgram
    {
        $this->assertPositiveId($resultId, 'Result tied program result identity');
        $this->assertPositiveId($programId, 'Result tied program program identity');

        $statement = $this->connection()->prepare(
            'INSERT INTO result_tied_programs (result_id, program_id)
             VALUES (:result_id, :program_id)'
        );
        $statement->execute([
            'result_id' => $resultId,
            'program_id' => $programId,
        ]);

        return new ResultTiedProgram($resultId, $programId);
    }

    /** @return list<int> */
    public function findTiedProgramIdsByResultId(int $resultId): array
    {
        $this->assertPositiveId($resultId, 'Result tied program result identity');

        $statement = $this->connection()->prepare(
            'SELECT program_id
             FROM result_tied_programs
             WHERE result_id = :result_id
             ORDER BY program_id ASC'
        );
        $statement->execute(['result_id' => $resultId]);
        $programIds = [];

        foreach ($statement->fetchAll() as $row) {
            $programIds[] = $this->rowInt($row, 'program_id');
        }

        return $programIds;
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
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted result tied program data.');
        }

        return (int) $row[$key];
    }
}
