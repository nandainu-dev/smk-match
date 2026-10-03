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
            'SELECT id, name, short_name, mascot_path, skills_json
             FROM programs
             WHERE school_id = :school_id
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute(['school_id' => $schoolId]);

        return array_map(fn (array $row): array => $this->programWithPresentation($row), $statement->fetchAll());
    }

    /** @return array{id: int, name: string, code: string, mascot_path: ?string}|null */
    public function findProgramForSchool(int $schoolId, int $programId): ?array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        $statement = $this->connection()->prepare(
            'SELECT id, name, short_name, mascot_path, skills_json
             FROM programs
             WHERE id = :program_id AND school_id = :school_id'
        );
        $statement->execute(['program_id' => $programId, 'school_id' => $schoolId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->programWithPresentation($row);
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

    /** @param list<string> $skills @param list<string> $careers @return array<string, mixed> */
    public function savePresentationContent(
        int $schoolId,
        int $programId,
        array $skills,
        array $careers,
    ): array {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        $skillsJson = json_encode(
            $skills,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $lock = $connection->prepare(
                'SELECT id
                 FROM programs
                 WHERE id = :program_id AND school_id = :school_id
                 FOR UPDATE'
            );

            $lock->execute([
                'program_id' => $programId,
                'school_id' => $schoolId,
            ]);

            if ($lock->fetch() === false) {
                throw new \RuntimeException(
                    'Program does not belong to the authenticated school.'
                );
            }

            $update = $connection->prepare(
                'UPDATE programs
                 SET skills_json = :skills_json,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :program_id AND school_id = :school_id'
            );

            $update->execute([
                'skills_json' => $skillsJson,
                'program_id' => $programId,
                'school_id' => $schoolId,
            ]);

            $delete = $connection->prepare(
                'DELETE FROM program_careers
                 WHERE program_id = :program_id'
            );

            $delete->execute([
                'program_id' => $programId,
            ]);

            $insert = $connection->prepare(
                'INSERT INTO program_careers (
                    program_id,
                    title,
                    description,
                    sort_order,
                    created_at
                 ) VALUES (
                    :program_id,
                    :title,
                    NULL,
                    :sort_order,
                    UTC_TIMESTAMP()
                 )'
            );

            foreach ($careers as $order => $career) {
                $insert->execute([
                    'program_id' => $programId,
                    'title' => $career,
                    'sort_order' => $order,
                ]);
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }

        $program = $this->findProgramForSchool(
            $schoolId,
            $programId,
        );

        if (
            $program === null
            || $program['skills'] !== $skills
            || $program['careers'] !== $careers
        ) {
            throw new \RuntimeException(
                'Program presentation content could not be reloaded.'
            );
        }

        return $program;
    }

    /** @return array<string, mixed> */
    private function programWithPresentation(array $row): array
    {
        $program = $this->program($row);

        $program['skills'] = $this->stringListFromJson(
            $row['skills_json'] ?? null,
        );

        $program['careers'] = $this->careersForProgram(
            (int) $program['id'],
        );

        return $program;
    }

    /** @return list<string> */
    private function careersForProgram(int $programId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT title
             FROM program_careers
             WHERE program_id = :program_id
             ORDER BY sort_order ASC, id ASC'
        );

        $statement->execute([
            'program_id' => $programId,
        ]);

        $careers = [];

        foreach ($statement->fetchAll() as $row) {
            if (
                !isset($row['title'])
                || !is_string($row['title'])
                || trim($row['title']) === ''
            ) {
                throw new \RuntimeException(
                    'Invalid program career persistence state.'
                );
            }

            $careers[] = trim($row['title']);
        }

        return $careers;
    }

    /** @return list<string> */
    private function stringListFromJson(mixed $value): array
    {
        if (
            $value === null
            || !is_string($value)
            || trim($value) === ''
        ) {
            return [];
        }

        $decoded = json_decode(
            $value,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                'Program skills persistence state is invalid.'
            );
        }

        $items = [];

        foreach ($decoded as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new \RuntimeException(
                    'Program skills persistence state is invalid.'
                );
            }

            $items[] = trim($item);
        }

        return $items;
    }

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
