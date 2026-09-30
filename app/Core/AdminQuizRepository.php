<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class AdminQuizRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return list<array{id: int, name: string, versions: list<array{id: int, version_number: int, name: string, status: string, is_used: bool}>}>
     */
    public function listQuizzesForSchool(int $schoolId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');

        $statement = $this->connection()->prepare(
            'SELECT q.id AS quiz_id,
                    q.name AS quiz_name,
                    qv.id AS version_id,
                    qv.version_number,
                    qv.name AS version_name,
                    qv.status,
                    EXISTS(SELECT 1 FROM attempts AS a WHERE a.quiz_version_id = qv.id) AS is_used
             FROM quizzes AS q
             LEFT JOIN quiz_versions AS qv ON qv.quiz_id = q.id
             WHERE q.school_id = :school_id
             ORDER BY q.id ASC, qv.version_number DESC, qv.id DESC'
        );
        $statement->execute(['school_id' => $schoolId]);

        $quizzes = [];
        foreach ($statement->fetchAll() as $row) {
            $quizId = $this->rowPositiveInt($row, 'quiz_id');
            if (!isset($quizzes[$quizId])) {
                $quizzes[$quizId] = [
                    'id' => $quizId,
                    'name' => $this->rowString($row, 'quiz_name'),
                    'versions' => [],
                ];
            }

            if ($row['version_id'] === null) {
                continue;
            }

            $quizzes[$quizId]['versions'][] = [
                'id' => $this->rowPositiveInt($row, 'version_id'),
                'version_number' => $this->rowPositiveInt($row, 'version_number'),
                'name' => $this->rowString($row, 'version_name'),
                'status' => $this->rowString($row, 'status'),
                'is_used' => (bool) $this->rowInt($row, 'is_used'),
            ];
        }

        return array_values($quizzes);
    }

    /** @return array{id: int, name: string}|null */
    public function findQuizForSchool(int $schoolId, int $quizId): ?array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($quizId, 'Quiz identity');

        $statement = $this->connection()->prepare(
            'SELECT id, name
             FROM quizzes
             WHERE id = :quiz_id AND school_id = :school_id'
        );
        $statement->execute(['quiz_id' => $quizId, 'school_id' => $schoolId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => $this->rowPositiveInt($row, 'id'),
            'name' => $this->rowString($row, 'name'),
        ];
    }

    public function versionBelongsToSchool(int $schoolId, int $quizId, int $versionId): bool
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($quizId, 'Quiz identity');
        $this->assertPositiveId($versionId, 'Quiz version identity');

        $statement = $this->connection()->prepare(
            'SELECT EXISTS(
                SELECT 1
                FROM quiz_versions AS qv
                INNER JOIN quizzes AS q ON q.id = qv.quiz_id
                WHERE qv.id = :version_id
                  AND qv.quiz_id = :quiz_id
                  AND q.school_id = :school_id
            )'
        );
        $statement->execute([
            'school_id' => $schoolId,
            'quiz_id' => $quizId,
            'version_id' => $versionId,
        ]);

        return (bool) $statement->fetchColumn();
    }

    /** @return array<string, bool> */
    public function activeProgramCodesForSchool(int $schoolId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');

        $statement = $this->connection()->prepare(
            'SELECT short_name
             FROM programs
             WHERE school_id = :school_id AND is_active = 1
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute(['school_id' => $schoolId]);

        $programs = [];
        foreach ($statement->fetchAll() as $row) {
            $code = $this->rowString($row, 'short_name');
            if (isset($programs[$code])) {
                throw new RuntimeException('Duplicate active program code in school scope.');
            }
            $programs[$code] = true;
        }

        return $programs;
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
    private function rowPositiveInt(array $row, string $key): int
    {
        $value = $this->rowInt($row, $key);
        if ($value < 1) {
            throw new RuntimeException('Invalid persisted admin quiz data.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted admin quiz data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key]) || trim($row[$key]) === '') {
            throw new RuntimeException('Invalid persisted admin quiz data.');
        }

        return $row[$key];
    }
}
