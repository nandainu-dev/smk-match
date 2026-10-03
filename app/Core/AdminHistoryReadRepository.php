<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class AdminHistoryReadRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     summary: list<array{code: string, name: string, dominant_count: int, score_average_percentage: float}>
     * }
     */
    public function read(
        int $schoolId,
        ?int $campaignId,
        ?int $batchId,
        ?string $fromUtc,
        ?string $untilUtc,
    ): array {
        $this->assertPositiveId($schoolId, 'School identity');
        if ($campaignId !== null) {
            $this->assertPositiveId($campaignId, 'Campaign identity');
        }
        if ($batchId !== null) {
            $this->assertPositiveId($batchId, 'Campaign batch identity');
        }

        [$where, $parameters] = $this->filter($schoolId, $campaignId, $batchId, $fromUtc, $untilUtc);
        $statement = $this->connection()->prepare(
            'SELECT a.id AS attempt_id, a.quiz_version_id, a.created_at AS attempt_created_at,
                    a.status AS attempt_status, c.name AS campaign_name, cb.batch_number,
                    cb.label AS batch_label, p.full_name AS participant_name, p.origin_school, p.class_name, p.phone, r.id AS result_id,
                    r.is_tie, r.dominant_program_id
             FROM attempts AS a
             INNER JOIN campaigns AS c ON c.id = a.campaign_id
             LEFT JOIN campaign_batches AS cb ON cb.id = a.campaign_batch_id
             LEFT JOIN participants AS p ON p.id = a.participant_id
             LEFT JOIN results AS r ON r.attempt_id = a.id
             WHERE ' . $where . '
             ORDER BY a.created_at DESC, a.id DESC'
        );
        $statement->execute($parameters);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $resultId = $this->nullablePositiveInt($row, 'result_id');
            $quizVersionId = $this->positiveInt($row, 'quiz_version_id');
            $isTie = $resultId === null ? false : $this->bool($row, 'is_tie');
            $dominantProgramId = $resultId === null ? null : $this->nullablePositiveInt($row, 'dominant_program_id');

            if ($resultId !== null && $isTie === false && $dominantProgramId === null) {
                throw new RuntimeException('Historical result has no dominant program.');
            }

            $rows[] = [
                'participant_name' => $this->nullableString($row, 'participant_name') ?? 'Peserta',
                'origin_school' => $this->nullableString($row, 'origin_school'),
                'class_name' => $this->nullableString($row, 'class_name'),
                'phone' => $this->nullableString($row, 'phone'),
                'attempt_created_at' => $this->string($row, 'attempt_created_at'),
                'attempt_status' => $this->string($row, 'attempt_status'),
                'campaign_name' => $this->string($row, 'campaign_name'),
                'batch_number' => $this->nullablePositiveInt($row, 'batch_number'),
                'batch_label' => $this->nullableString($row, 'batch_label'),
                'outcome' => $resultId === null
                    ? null
                    : $this->outcome($resultId, $quizVersionId, $isTie, $dominantProgramId),
                'ranking' => $resultId === null ? [] : $this->ranking($resultId, $quizVersionId),
            ];
        }

        return [
            'rows' => $rows,
            'summary' => $this->summary($where, $parameters),
        ];
    }

    /** @return array{0: string, 1: list<int|string>} */
    private function filter(int $schoolId, ?int $campaignId, ?int $batchId, ?string $fromUtc, ?string $untilUtc): array
    {
        $conditions = ['c.school_id = ?'];
        $parameters = [$schoolId];

        if ($campaignId !== null) {
            $conditions[] = 'a.campaign_id = ?';
            $parameters[] = $campaignId;
        }
        if ($batchId !== null) {
            $conditions[] = 'a.campaign_batch_id = ?';
            $parameters[] = $batchId;
        }
        if ($fromUtc !== null) {
            $conditions[] = 'a.created_at >= ?';
            $parameters[] = $fromUtc;
        }
        if ($untilUtc !== null) {
            $conditions[] = 'a.created_at < ?';
            $parameters[] = $untilUtc;
        }

        return [implode(' AND ', $conditions), $parameters];
    }

    /** @return array{kind: string, dominant_program: ?array{code: string, name: string}, tied_programs: list<array{code: string, name: string}>} */
    private function outcome(int $resultId, int $quizVersionId, bool $isTie, ?int $dominantProgramId): array
    {
        if (!$isTie) {
            return [
                'kind' => 'decisive',
                'dominant_program' => $this->snapshotProgram($quizVersionId, $dominantProgramId ?? 0),
                'tied_programs' => [],
            ];
        }

        $statement = $this->connection()->prepare(
            'SELECT qvpp.program_code_snapshot, qvpp.program_name_snapshot
             FROM result_tied_programs AS rtp
             INNER JOIN quiz_version_programs AS qvp
                ON qvp.program_id = rtp.program_id
               AND qvp.quiz_version_id = :quiz_version_id
             INNER JOIN quiz_version_program_presentations AS qvpp
                ON qvpp.quiz_version_program_id = qvp.id
             WHERE rtp.result_id = :result_id
             ORDER BY qvpp.program_code_snapshot ASC'
        );
        $statement->execute([
            'quiz_version_id' => $quizVersionId,
            'result_id' => $resultId,
        ]);
        $tiedPrograms = [];
        foreach ($statement->fetchAll() as $row) {
            $tiedPrograms[] = $this->snapshotProgramRow($row);
        }
        if (count($tiedPrograms) < 2) {
            throw new RuntimeException('Historical tied result has incomplete tied-program snapshots.');
        }

        return [
            'kind' => 'tie',
            'dominant_program' => null,
            'tied_programs' => $tiedPrograms,
        ];
    }

    /** @return list<array{display_order: int, normalized_percentage: float, program: array{code: string, name: string}}> */
    private function ranking(int $resultId, int $quizVersionId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT rs.display_order, rs.normalized_percentage,
                    qvpp.program_code_snapshot, qvpp.program_name_snapshot
             FROM result_scores AS rs
             INNER JOIN quiz_version_programs AS qvp
                ON qvp.program_id = rs.program_id
               AND qvp.quiz_version_id = :quiz_version_id
             INNER JOIN quiz_version_program_presentations AS qvpp
                ON qvpp.quiz_version_program_id = qvp.id
             WHERE rs.result_id = :result_id
             ORDER BY rs.display_order ASC'
        );
        $statement->execute([
            'quiz_version_id' => $quizVersionId,
            'result_id' => $resultId,
        ]);

        $ranking = [];
        foreach ($statement->fetchAll() as $row) {
            $ranking[] = [
                'display_order' => $this->positiveInt($row, 'display_order'),
                'normalized_percentage' => $this->finiteFloat($row, 'normalized_percentage'),
                'program' => $this->snapshotProgramRow($row),
            ];
        }

        return $ranking;
    }

    /** @param list<int|string> $parameters @return list<array{code: string, name: string, dominant_count: int, score_average_percentage: float}> */
    private function summary(string $where, array $parameters): array
    {
        $statement = $this->connection()->prepare(
            'SELECT qvpp.program_code_snapshot, qvpp.program_name_snapshot,
                    COALESCE(SUM(CASE WHEN r.is_tie = 0 AND r.dominant_program_id = qvp.program_id THEN 1 ELSE 0 END), 0) AS dominant_count,
                    COALESCE(AVG(rs.normalized_percentage), 0) AS score_average_percentage
             FROM attempts AS a
             INNER JOIN campaigns AS c ON c.id = a.campaign_id
             INNER JOIN results AS r ON r.attempt_id = a.id
             INNER JOIN result_scores AS rs ON rs.result_id = r.id
             INNER JOIN quiz_version_programs AS qvp
                ON qvp.program_id = rs.program_id
               AND qvp.quiz_version_id = a.quiz_version_id
             INNER JOIN quiz_version_program_presentations AS qvpp
                ON qvpp.quiz_version_program_id = qvp.id
             WHERE ' . $where . '
             GROUP BY a.quiz_version_id, qvp.program_id, qvpp.program_code_snapshot, qvpp.program_name_snapshot
             ORDER BY qvpp.program_code_snapshot ASC, qvp.program_id ASC'
        );
        $statement->execute($parameters);

        $summary = [];
        foreach ($statement->fetchAll() as $row) {
            $summary[] = [
                ...$this->snapshotProgramRow($row),
                'dominant_count' => $this->nonNegativeInt($row, 'dominant_count'),
                'score_average_percentage' => $this->finiteFloat($row, 'score_average_percentage'),
            ];
        }

        return $summary;
    }

    /** @return array{code: string, name: string} */
    private function snapshotProgram(int $quizVersionId, int $programId): array
    {
        if ($programId < 1) {
            throw new RuntimeException('Historical result has an invalid dominant program.');
        }

        $statement = $this->connection()->prepare(
            'SELECT qvpp.program_code_snapshot, qvpp.program_name_snapshot
             FROM quiz_version_programs AS qvp
             INNER JOIN quiz_version_program_presentations AS qvpp
                ON qvpp.quiz_version_program_id = qvp.id
             WHERE qvp.quiz_version_id = :quiz_version_id
               AND qvp.program_id = :program_id'
        );
        $statement->execute([
            'quiz_version_id' => $quizVersionId,
            'program_id' => $programId,
        ]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new RuntimeException('Historical result dominant-program snapshot is missing.');
        }

        return $this->snapshotProgramRow($row);
    }

    /** @param array<string, mixed> $row @return array{code: string, name: string} */
    private function snapshotProgramRow(array $row): array
    {
        return [
            'code' => $this->string($row, 'program_code_snapshot'),
            'name' => $this->string($row, 'program_name_snapshot'),
        ];
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
    private function positiveInt(array $row, string $key): int
    {
        $value = $this->nonNegativeInt($row, $key);
        if ($value < 1) {
            throw new RuntimeException('Invalid persisted historical reporting data.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullablePositiveInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('Invalid persisted historical reporting data.');
        }
        if ($row[$key] === null) {
            return null;
        }

        return $this->positiveInt($row, $key);
    }

    /** @param array<string, mixed> $row */
    private function nonNegativeInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || (int) $row[$key] < 0) {
            throw new RuntimeException('Invalid persisted historical reporting data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key]) || trim($row[$key]) === '') {
            throw new RuntimeException('Invalid persisted historical reporting data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid persisted historical reporting data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function bool(array $row, string $key): bool
    {
        $value = $this->nonNegativeInt($row, $key);
        if ($value !== 0 && $value !== 1) {
            throw new RuntimeException('Invalid persisted historical reporting data.');
        }

        return $value === 1;
    }

    /** @param array<string, mixed> $row */
    private function finiteFloat(array $row, string $key): float
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || !is_finite((float) $row[$key])) {
            throw new RuntimeException('Invalid persisted historical reporting data.');
        }

        return (float) $row[$key];
    }
}
