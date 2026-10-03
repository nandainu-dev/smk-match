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

    /** @return list<array<string, mixed>> */
    public function listProgramsForSchool(int $schoolId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');

        $statement = $this->connection()->prepare(
            'SELECT id, name, short_name, mascot_path, result_image_path, share_image_path, monitor_image_path, skills_json
             FROM programs
             WHERE school_id = :school_id
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute(['school_id' => $schoolId]);

        return array_map(fn (array $row): array => $this->program($row), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findProgramForSchool(int $schoolId, int $programId): ?array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        $statement = $this->connection()->prepare(
            'SELECT id, name, short_name, mascot_path, result_image_path, share_image_path, monitor_image_path, skills_json
             FROM programs
             WHERE id = :program_id AND school_id = :school_id'
        );
        $statement->execute(['program_id' => $programId, 'school_id' => $schoolId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->program($row);
    }

    /** @return array<string, int|string|null> */
    public function recordMascotAndSetCurrent(int $schoolId, int $programId, string $publicPath): array
    {
        return $this->recordMediaAndSetCurrent($schoolId, $programId, 'mascot', $publicPath);
    }

    /** @return array<string, int|string|null> */
    public function recordMediaAndSetCurrent(int $schoolId, int $programId, string $role, string $publicPath): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');
        if (!ProgramMediaStorage::isCanonicalPublicPath($publicPath)) {
            throw new \InvalidArgumentException('Program media path must be canonical.');
        }
        $column = $this->mediaColumn($role);

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
                'media_type' => $role,
            ]);

            $update = $connection->prepare(
                'UPDATE programs
                 SET ' . $column . ' = :media_path, updated_at = UTC_TIMESTAMP()
                 WHERE id = :program_id AND school_id = :school_id'
            );
            $update->execute([
                'media_path' => $publicPath,
                'program_id' => $programId,
                'school_id' => $schoolId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Program media update was not applied.');
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }

        $program = $this->findProgramForSchool($schoolId, $programId);
        if ($program === null || $program[$column] !== $publicPath) {
            throw new RuntimeException('Program media could not be reloaded.');
        }

        return $program;
    }

    /** @return array<string, int|string|null> */
    public function clearCurrentMascot(int $schoolId, int $programId): array
    {
        return $this->clearCurrentMedia($schoolId, $programId, 'mascot');
    }

    /** @return array<string, int|string|null> */
    public function clearCurrentMedia(int $schoolId, int $programId, string $role): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        $column = $this->mediaColumn($role);
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->lockOwnedProgram($connection, $schoolId, $programId);
            $update = $connection->prepare(
                'UPDATE programs
                 SET ' . $column . ' = NULL, updated_at = UTC_TIMESTAMP()
                 WHERE id = :program_id AND school_id = :school_id'
            );
            $update->execute(['program_id' => $programId, 'school_id' => $schoolId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Program media removal was not applied.');
            }
            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }

        $program = $this->findProgramForSchool($schoolId, $programId);
        if ($program === null || $program[$column] !== null) {
            throw new RuntimeException('Program media removal could not be reloaded.');
        }

        return $program;
    }

    /** @return array{id: int, name: string, code: string, mascot_path: ?string} */
    public function renameProgram(int $schoolId, int $programId, string $name, string $code): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->lockOwnedProgram($connection, $schoolId, $programId);
            $update = $connection->prepare(
                'UPDATE programs
                 SET name = :name, short_name = :short_name, updated_at = UTC_TIMESTAMP()
                 WHERE id = :program_id AND school_id = :school_id'
            );
            $update->execute([
                'name' => $name,
                'short_name' => $code,
                'program_id' => $programId,
                'school_id' => $schoolId,
            ]);
            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }

        $program = $this->findProgramForSchool($schoolId, $programId);
        if ($program === null || $program['name'] !== $name || $program['code'] !== $code) {
            throw new RuntimeException('Program name update could not be reloaded.');
        }

        return $program;
    }

    /** @param list<string> $skills @param list<string> $careers @return array<string, mixed> */
    public function savePresentationContent(int $schoolId, int $programId, array $skills, array $careers): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($programId, 'Program identity');

        $skillsJson = json_encode($skills, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->lockOwnedProgram($connection, $schoolId, $programId);
            $update = $connection->prepare(
                'UPDATE programs
                 SET skills_json = :skills_json, updated_at = UTC_TIMESTAMP()
                 WHERE id = :program_id AND school_id = :school_id'
            );
            $update->execute(['skills_json' => $skillsJson, 'program_id' => $programId, 'school_id' => $schoolId]);

            $delete = $connection->prepare('DELETE FROM program_careers WHERE program_id = :program_id');
            $delete->execute(['program_id' => $programId]);
            $insert = $connection->prepare(
                'INSERT INTO program_careers (program_id, title, description, sort_order, created_at)
                 VALUES (:program_id, :title, NULL, :sort_order, UTC_TIMESTAMP())'
            );
            foreach ($careers as $order => $career) {
                $insert->execute(['program_id' => $programId, 'title' => $career, 'sort_order' => $order]);
            }
            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }

        $program = $this->findProgramForSchool($schoolId, $programId);
        if ($program === null || $program['skills'] !== $skills || $program['careers'] !== $careers) {
            throw new RuntimeException('Program presentation content could not be reloaded.');
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

    private function mediaColumn(string $role): string
    {
        return match ($role) {
            'mascot' => 'mascot_path',
            'result' => 'result_image_path',
            'share' => 'share_image_path',
            'monitor' => 'monitor_image_path',
            default => throw new \InvalidArgumentException('Program media role is invalid.'),
        };
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

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function program(array $row): array
    {
        $programId = $this->positiveRowInt($row, 'id');

        return [
            'id' => $programId,
            'name' => $this->nonBlankRowString($row, 'name'),
            'code' => $this->nonBlankRowString($row, 'short_name'),
            'mascot_path' => $this->nullableRowString($row, 'mascot_path'),
            'result_image_path' => $this->nullableRowString($row, 'result_image_path'),
            'share_image_path' => $this->nullableRowString($row, 'share_image_path'),
            'monitor_image_path' => $this->nullableRowString($row, 'monitor_image_path'),
            'skills' => $this->nullableStringList($row, 'skills_json'),
            'careers' => $this->careersForProgram($programId),
        ];
    }

    /** @param array<string, mixed> $row @return list<string> */
    private function nullableStringList(array $row, string $key): array
    {
        $json = $this->nullableRowString($row, $key);
        if ($json === null || trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Invalid persisted program presentation data.');
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('Invalid persisted program presentation data.');
        }

        $items = [];
        foreach ($decoded as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException('Invalid persisted program presentation data.');
            }
            $items[] = $value;
        }

        return $items;
    }

    /** @return list<string> */
    private function careersForProgram(int $programId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT title FROM program_careers WHERE program_id = :program_id ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute(['program_id' => $programId]);

        return array_map(
            fn (array $row): string => $this->nonBlankRowString($row, 'title'),
            $statement->fetchAll(),
        );
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
