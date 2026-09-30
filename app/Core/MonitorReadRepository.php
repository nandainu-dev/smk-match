<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class MonitorReadRepository
{
    private const COMPLETED_STATUS = Attempt::STATUS_COMPLETED;
    private const ACTIVE_BATCH_STATUS = CampaignBatch::STATUS_ACTIVE;
    private const MAX_RECENT_EVENTS = 50;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return array{
     *     batch: array{id: int, campaign_id: int, batch_number: int, quiz_version_id: int, label: ?string, started_at: string},
     *     summary: array{started_count: int, completed_count: int, tie_count: int},
     *     programs: list<array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string, dominant_count: int, score_average_percentage: float}>,
     *     recent_events: list<array{result_id: int, participant_name: string, submitted_at: string, is_tie: bool, dominant_program: ?array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}, tied_programs: list<array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}>, ranking: list<array{display_order: int, normalized_percentage: float, program: array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}}>}
     * }|null
     */
    public function readActiveBatch(int $campaignId, int $recentLimit = 20): ?array
    {
        $this->assertPositiveId($campaignId, 'Campaign identity');
        $this->assertRecentLimit($recentLimit);

        $batch = $this->findActiveBatch($campaignId);
        if ($batch === null) {
            return null;
        }

        $programsById = $this->findSnapshotProgramsByVersionId($batch['quiz_version_id']);
        $summary = $this->findSummary($batch['id']);

        return [
            'batch' => $batch,
            'summary' => $summary,
            'programs' => $this->findProgramMetrics(
                $batch['id'],
                $batch['quiz_version_id'],
                $summary['completed_count'],
                $programsById,
            ),
            'recent_events' => $this->findRecentEvents(
                $batch['id'],
                $batch['quiz_version_id'],
                $recentLimit,
                $programsById,
            ),
        ];
    }

    /** @return array{id: int, campaign_id: int, batch_number: int, quiz_version_id: int, label: ?string, started_at: string}|null */
    private function findActiveBatch(int $campaignId): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT id, campaign_id, batch_number, quiz_version_id, label, started_at
             FROM campaign_batches
             WHERE campaign_id = :campaign_id
               AND active_marker = 1
               AND status = :status'
        );
        $statement->execute([
            'campaign_id' => $campaignId,
            'status' => self::ACTIVE_BATCH_STATUS,
        ]);
        $rows = $statement->fetchAll();

        if (count($rows) > 1) {
            throw new RuntimeException('Persistence invariant violation: multiple active campaign batches exist.');
        }

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return [
            'id' => $this->rowPositiveInt($row, 'id'),
            'campaign_id' => $this->rowPositiveInt($row, 'campaign_id'),
            'batch_number' => $this->rowPositiveInt($row, 'batch_number'),
            'quiz_version_id' => $this->rowPositiveInt($row, 'quiz_version_id'),
            'label' => $this->rowNullableString($row, 'label'),
            'started_at' => $this->rowString($row, 'started_at'),
        ];
    }

    /** @return array{started_count: int, completed_count: int, tie_count: int} */
    private function findSummary(int $batchId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                COUNT(*) AS started_count,
                COALESCE(SUM(CASE WHEN a.status = :completed_count_status AND r.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS completed_count,
                COALESCE(SUM(CASE WHEN a.status = :tie_count_status AND r.id IS NOT NULL AND r.is_tie = 1 THEN 1 ELSE 0 END), 0) AS tie_count
             FROM attempts AS a
             LEFT JOIN results AS r ON r.attempt_id = a.id
             WHERE a.campaign_batch_id = :batch_id'
        );
        $statement->execute([
            'batch_id' => $batchId,
            'completed_count_status' => self::COMPLETED_STATUS,
            'tie_count_status' => self::COMPLETED_STATUS,
        ]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new RuntimeException('Monitor summary could not be read.');
        }

        return [
            'started_count' => $this->rowNonNegativeInt($row, 'started_count'),
            'completed_count' => $this->rowNonNegativeInt($row, 'completed_count'),
            'tie_count' => $this->rowNonNegativeInt($row, 'tie_count'),
        ];
    }

    /**
     * @return array<int, array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}>
     */
    private function findSnapshotProgramsByVersionId(int $quizVersionId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT qvp.program_id,
                    qvpp.program_code_snapshot,
                    qvpp.program_name_snapshot,
                    qvpp.personality_title_snapshot,
                    qvpp.mascot_path_snapshot,
                    qvpp.primary_color_snapshot,
                    qvpp.accent_color_snapshot,
                    qvpp.tagline_snapshot,
                    qvpp.description_snapshot,
                    qvpp.superpower_snapshot,
                    qvpp.skills_snapshot,
                    qvpp.careers_snapshot
             FROM quiz_version_programs AS qvp
             INNER JOIN quiz_version_program_presentations AS qvpp
                ON qvpp.quiz_version_program_id = qvp.id
             WHERE qvp.quiz_version_id = :quiz_version_id
             ORDER BY qvpp.program_code_snapshot ASC, qvp.program_id ASC'
        );
        $statement->execute(['quiz_version_id' => $quizVersionId]);
        $programsById = [];

        foreach ($statement->fetchAll() as $row) {
            $programId = $this->rowPositiveInt($row, 'program_id');
            if (isset($programsById[$programId])) {
                throw new RuntimeException('Persistence invariant violation: duplicate quiz version program.');
            }

            $programsById[$programId] = $this->snapshotProgram($row);
        }

        if ($programsById === []) {
            throw new RuntimeException('Persistence invariant violation: active batch has no presentation snapshots.');
        }

        return $programsById;
    }

    /**
     * @param array<int, array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}> $programsById
     * @return list<array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string, dominant_count: int, score_average_percentage: float}>
     */
    private function findProgramMetrics(
        int $batchId,
        int $quizVersionId,
        int $completedCount,
        array $programsById,
    ): array
    {
        $statement = $this->connection()->prepare(
            'SELECT qvp.program_id,
                    SUM(CASE
                        WHEN a.status = :dominant_count_status
                         AND r.id IS NOT NULL
                         AND r.is_tie = 0
                         AND r.dominant_program_id = qvp.program_id
                        THEN 1 ELSE 0
                    END) AS dominant_count,
                    COALESCE(SUM(CASE
                        WHEN a.status = :score_percentage_status
                         AND r.id IS NOT NULL
                        THEN rs.normalized_percentage ELSE 0
                    END), 0) AS score_percentage_sum
             FROM quiz_version_programs AS qvp
             LEFT JOIN result_scores AS rs ON rs.program_id = qvp.program_id
             LEFT JOIN results AS r ON r.id = rs.result_id
             LEFT JOIN attempts AS a
                ON a.id = r.attempt_id
               AND a.campaign_batch_id = :batch_id
             WHERE qvp.quiz_version_id = :quiz_version_id
             GROUP BY qvp.program_id'
        );
        $statement->execute([
            'dominant_count_status' => self::COMPLETED_STATUS,
            'score_percentage_status' => self::COMPLETED_STATUS,
            'batch_id' => $batchId,
            'quiz_version_id' => $quizVersionId,
        ]);

        $metricsById = [];
        foreach ($statement->fetchAll() as $row) {
            $programId = $this->rowPositiveInt($row, 'program_id');
            if (!isset($programsById[$programId])) {
                throw new RuntimeException('Persistence invariant violation: metric program is outside active version.');
            }

            $metricsById[$programId] = [
                'dominant_count' => $this->rowNonNegativeInt($row, 'dominant_count'),
                'score_percentage_sum' => $this->rowFiniteFloat($row, 'score_percentage_sum'),
            ];
        }

        if (count($metricsById) !== count($programsById)) {
            throw new RuntimeException('Persistence invariant violation: active version program metrics are incomplete.');
        }

        $programs = [];
        foreach ($programsById as $programId => $program) {
            $metrics = $metricsById[$programId];
            $programs[] = [
                ...$program,
                'dominant_count' => $metrics['dominant_count'],
                'score_average_percentage' => $completedCount === 0
                    ? 0.0
                    : $metrics['score_percentage_sum'] / $completedCount,
            ];
        }

        return $programs;
    }

    /**
     * @param array<int, array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}> $programsById
     * @return list<array{result_id: int, participant_name: string, submitted_at: string, is_tie: bool, dominant_program: ?array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}, tied_programs: list<array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}>, ranking: list<array{display_order: int, normalized_percentage: float, program: array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}}>}>
     */
    private function findRecentEvents(int $batchId, int $quizVersionId, int $recentLimit, array $programsById): array
    {
        $statement = $this->connection()->prepare(
            'SELECT r.id AS result_id,
                    a.submitted_at,
                    p.full_name AS participant_name,
                    r.is_tie,
                    r.dominant_program_id
             FROM attempts AS a
             INNER JOIN results AS r ON r.attempt_id = a.id
             INNER JOIN participants AS p ON p.id = a.participant_id
             WHERE a.campaign_batch_id = :batch_id
               AND a.status = :completed_status
               AND a.submitted_at IS NOT NULL
             ORDER BY a.submitted_at DESC, r.id DESC
             LIMIT :recent_limit'
        );
        $statement->bindValue('batch_id', $batchId, PDO::PARAM_INT);
        $statement->bindValue('completed_status', self::COMPLETED_STATUS, PDO::PARAM_STR);
        $statement->bindValue('recent_limit', $recentLimit, PDO::PARAM_INT);
        $statement->execute();
        $events = [];

        foreach ($statement->fetchAll() as $row) {
            $resultId = $this->rowPositiveInt($row, 'result_id');
            $isTie = $this->rowBool($row, 'is_tie');
            $dominantProgramId = $this->rowNullablePositiveInt($row, 'dominant_program_id');
            if ($isTie && $dominantProgramId !== null) {
                throw new RuntimeException('Persistence invariant violation: tied monitor event has a dominant program.');
            }
            if (!$isTie && $dominantProgramId === null) {
                throw new RuntimeException('Persistence invariant violation: decisive monitor event has no dominant program.');
            }

            $events[] = [
                'result_id' => $resultId,
                'participant_name' => $this->rowNonBlankString($row, 'participant_name'),
                'submitted_at' => $this->rowString($row, 'submitted_at'),
                'is_tie' => $isTie,
                'dominant_program' => $dominantProgramId === null
                    ? null
                    : $this->requireSnapshotProgram($programsById, $dominantProgramId),
                'tied_programs' => $isTie
                    ? $this->findTiedPrograms($resultId, $quizVersionId, $programsById)
                    : [],
                'ranking' => $this->findRanking($resultId, $quizVersionId, $programsById),
            ];
        }

        return $events;
    }

    /**
     * @param array<int, array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}> $programsById
     * @return list<array{display_order: int, normalized_percentage: float, program: array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}}>
     */
    private function findRanking(int $resultId, int $quizVersionId, array $programsById): array
    {
        $statement = $this->connection()->prepare(
            'SELECT rs.program_id, rs.display_order, rs.normalized_percentage
             FROM result_scores AS rs
             INNER JOIN quiz_version_programs AS qvp
                ON qvp.program_id = rs.program_id
               AND qvp.quiz_version_id = :quiz_version_id
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
                'display_order' => $this->rowPositiveInt($row, 'display_order'),
                'normalized_percentage' => $this->rowFiniteFloat($row, 'normalized_percentage'),
                'program' => $this->requireSnapshotProgram($programsById, $this->rowPositiveInt($row, 'program_id')),
            ];
        }

        if (count($ranking) !== count($programsById)) {
            throw new RuntimeException('Persistence invariant violation: monitor event ranking is incomplete.');
        }

        return $ranking;
    }

    /**
     * @param array<int, array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}> $programsById
     * @return list<array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}>
     */
    private function findTiedPrograms(int $resultId, int $quizVersionId, array $programsById): array
    {
        $statement = $this->connection()->prepare(
            'SELECT rtp.program_id
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
        $programs = [];

        foreach ($statement->fetchAll() as $row) {
            $programs[] = $this->requireSnapshotProgram($programsById, $this->rowPositiveInt($row, 'program_id'));
        }

        if (count($programs) < 2) {
            throw new RuntimeException('Persistence invariant violation: tied monitor event has fewer than two tied programs.');
        }

        return $programs;
    }

    /**
     * @param array<int, array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}> $programsById
     * @return array{code: string, name: string, personality_title: ?string, mascot_path: ?string, primary_color: ?string, accent_color: ?string, tagline: ?string, description: ?string, superpower: ?string, skills: ?string, careers: ?string}
     */
    private function requireSnapshotProgram(array $programsById, int $programId): array
    {
        if (!isset($programsById[$programId])) {
            throw new RuntimeException('Persistence invariant violation: result program is outside the active version snapshot.');
        }

        return $programsById[$programId];
    }

    /** @param array<string, mixed> $row */
    private function snapshotProgram(array $row): array
    {
        return [
            'code' => $this->rowNonBlankString($row, 'program_code_snapshot'),
            'name' => $this->rowNonBlankString($row, 'program_name_snapshot'),
            'personality_title' => $this->rowNullableString($row, 'personality_title_snapshot'),
            'mascot_path' => $this->rowNullableString($row, 'mascot_path_snapshot'),
            'primary_color' => $this->rowNullableString($row, 'primary_color_snapshot'),
            'accent_color' => $this->rowNullableString($row, 'accent_color_snapshot'),
            'tagline' => $this->rowNullableString($row, 'tagline_snapshot'),
            'description' => $this->rowNullableString($row, 'description_snapshot'),
            'superpower' => $this->rowNullableString($row, 'superpower_snapshot'),
            'skills' => $this->rowNullableString($row, 'skills_snapshot'),
            'careers' => $this->rowNullableString($row, 'careers_snapshot'),
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

    private function assertRecentLimit(int $recentLimit): void
    {
        if ($recentLimit < 1 || $recentLimit > self::MAX_RECENT_EVENTS) {
            throw new \InvalidArgumentException('Recent monitor event limit must be between 1 and ' . self::MAX_RECENT_EVENTS . '.');
        }
    }

    /** @param array<string, mixed> $row */
    private function rowPositiveInt(array $row, string $key): int
    {
        $value = $this->rowNonNegativeInt($row, $key);
        if ($value < 1) {
            throw new RuntimeException('Invalid persisted monitor data.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function rowNonNegativeInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || (int) $row[$key] < 0) {
            throw new RuntimeException('Invalid persisted monitor data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowFiniteFloat(array $row, string $key): float
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || !is_finite((float) $row[$key])) {
            throw new RuntimeException('Invalid persisted monitor data.');
        }

        return (float) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowBool(array $row, string $key): bool
    {
        $value = $this->rowNonNegativeInt($row, $key);
        if ($value !== 0 && $value !== 1) {
            throw new RuntimeException('Invalid persisted monitor data.');
        }

        return $value === 1;
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key])) {
            throw new RuntimeException('Invalid persisted monitor data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNonBlankString(array $row, string $key): string
    {
        $value = $this->rowString($row, $key);
        if (trim($value) === '') {
            throw new RuntimeException('Invalid persisted monitor data.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function rowNullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid persisted monitor data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullablePositiveInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('Invalid persisted monitor data.');
        }

        if ($row[$key] === null) {
            return null;
        }

        return $this->rowPositiveInt($row, $key);
    }
}
