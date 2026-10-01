<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class AdminProgramMediaRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return list<array{id: int, name: string, code: string, mascot_path: ?string}> */
    public function listProgramsForSchool(int $schoolId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');

        $statement = $this->connection()->prepare(
            'SELECT id, name, short_name, mascot_path
             FROM programs
             WHERE school_id = :school_id
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute(['school_id' => $schoolId]);

        return array_map(fn (array $row): array => $this->program($row), $statement->fetchAll());
    }

    /** @return array{id: int, name: string, code: string, mascot_path: ?string}|null */
    public function findProgramForSchool(int $schoolId, int $programId): ?array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        $statement = $this->connection()->prepare(
            'SELECT id, name, short_name, mascot_path
             FROM programs
             WHERE id = :program_id AND school_id = :school_id'
        );
        $statement->execute(['program_id' => $programId, 'school_id' => $schoolId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->program($row);
    }

    /** @return array{id: int, name: string, code: string, mascot_path: ?string} */
    public function recordMascotAndSetCurrent(int $schoolId, int $programId, string $publicPath): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');
        if (!ProgramMediaStorage::isCanonicalPublicPath($publicPath)) {
            throw new \InvalidArgumentException('Program mascot path must be canonical.');
        }

        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->lockOwnedProgram($connection, $schoolId, $programId);
            $history = $connection->prepare(
                'INSERT INTO program_media (program_id, path, media_type, caption, sort_order, created_at)
                 VALUES (:program_id, :path, :media_type, NULL, 0, UTC_TIMESTAMP())'
            );
            $history->execute([
                'program_id' => $programId,
                'path' => $publicPath,
                'media_type' => 'mascot',
            ]);

            $update = $connection->prepare(
                'UPDATE programs
                 SET mascot_path = :mascot_path, updated_at = UTC_TIMESTAMP()
                 WHERE id = :program_id AND school_id = :school_id'
            );
            $update->execute([
                'mascot_path' => $publicPath,
                'program_id' => $programId,
                'school_id' => $schoolId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Program mascot update was not applied.');
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }

        $program = $this->findProgramForSchool($schoolId, $programId);
        if ($program === null || $program['mascot_path'] !== $publicPath) {
            throw new RuntimeException('Program mascot could not be reloaded.');
        }

        return $program;
    }

    /** @return array{id: int, name: string, code: string, mascot_path: ?string} */
    public function clearCurrentMascot(int $schoolId, int $programId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->lockOwnedProgram($connection, $schoolId, $programId);
            $update = $connection->prepare(
                'UPDATE programs
                 SET mascot_path = NULL, updated_at = UTC_TIMESTAMP()
                 WHERE id = :program_id AND school_id = :school_id'
            );
            $update->execute(['program_id' => $programId, 'school_id' => $schoolId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Program mascot removal was not applied.');
            }
            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }

        $program = $this->findProgramForSchool($schoolId, $programId);
        if ($program === null || $program['mascot_path'] !== null) {
            throw new RuntimeException('Program mascot removal could not be reloaded.');
        }

        return $program;
    }

    private function lockOwnedProgram(PDO $connection, int $schoolId, int $programId): void
    {
        $statement = $connection->prepare(
            'SELECT id
             FROM programs
             WHERE id = :program_id AND school_id = :school_id
             FOR UPDATE'
        );
        $statement->execute(['program_id' => $programId, 'school_id' => $schoolId]);
        if ($statement->fetch() === false) {
            throw new RuntimeException('Program does not belong to the authenticated school.');
        }
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

    /** @param array<string, mixed> $row @return array{id: int, name: string, code: string, mascot_path: ?string} */
    private function program(array $row): array
    {
        return [
            'id' => $this->positiveRowInt($row, 'id'),
            'name' => $this->nonBlankRowString($row, 'name'),
            'code' => $this->nonBlankRowString($row, 'short_name'),
            'mascot_path' => $this->nullableRowString($row, 'mascot_path'),
        ];
    }

    /** @param array<string, mixed> $row */
    private function positiveRowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || (int) $row[$key] < 1) {
            throw new RuntimeException('Invalid persisted program media data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function nonBlankRowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key]) || trim($row[$key]) === '') {
            throw new RuntimeException('Invalid persisted program media data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function nullableRowString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid persisted program media data.');
        }

        return $row[$key];
    }
}
