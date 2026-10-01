<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Read-only analytics projection over persisted attempt/result data.
 *
 * Program cohorts intentionally remain version-scoped: matching program codes in
 * different quiz versions are not silently treated as comparable history.
 */
final class AnalyticsReadRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return array{
     *     summary: array{started_attempt_count: int, completed_result_count: int, decisive_result_count: int, tie_count: int, tie_rate: array{numerator: int, denominator: int, rate: ?float}},
     *     batch_trends: list<array<string, int|string|null|float|array>>,
     *     campaign_trends: list<array<string, int|string|null|float|array>>,
     *     program_cohorts: list<array<string, int|string|null|float|array>>,
     *     comparison_cohorts: list<array{quiz_version_id: int, batch_ids: list<int>, program_count: int}>
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

        [$where, $parameters] = $this->attemptFilter($schoolId, $campaignId, $batchId, $fromUtc, $untilUtc);
        $attempts = $this->readAttempts($where, $parameters);
        $batches = $this->readBatches($schoolId, $campaignId, $batchId);
        $versionMembers = $this->readVersionMembers($this->versionIds($attempts, $batches));

        $completedResults = [];
        foreach ($attempts as $attempt) {
            $resultId = $attempt['result_id'];
            if ($attempt['status'] === Attempt::STATUS_COMPLETED && $resultId === null) {
                throw new RuntimeException('Persistence invariant violation: completed analytics attempt is missing a result.');
            }
            if ($attempt['status'] === Attempt::STATUS_STARTED && $resultId !== null) {
                throw new RuntimeException('Persistence invariant violation: started analytics attempt has a result.');
            }
            if ($resultId === null) {
                continue;
            }

            $this->assertResultState($attempt, $versionMembers);
            $completedResults[$resultId] = $attempt;
        }

        $scoresByResult = $this->readScores(array_keys($completedResults));
        $tiedProgramsByResult = $this->readTiedPrograms(array_keys($completedResults));
        $this->assertScoreAndTieInvariants($completedResults, $versionMembers, $scoresByResult, $tiedProgramsByResult);

        return $this->project($attempts, $batches, $versionMembers, $scoresByResult);
    }

    /** @return array{0: string, 1: list<int|string>} */
    private function attemptFilter(int $schoolId, ?int $campaignId, ?int $batchId, ?string $fromUtc, ?string $untilUtc): array
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

    /** @param list<int|string> $parameters @return list<array{attempt_id: int, campaign_id: int, campaign_batch_id: ?int, quiz_version_id: int, status: string, result_id: ?int, is_tie: ?bool, dominant_program_id: ?int}> */
    private function readAttempts(string $where, array $parameters): array
    {
        $statement = $this->connection()->prepare(
            'SELECT a.id AS attempt_id, a.campaign_id, a.campaign_batch_id, a.quiz_version_id, a.status,
                    r.id AS result_id, r.is_tie, r.dominant_program_id
             FROM attempts AS a
             INNER JOIN campaigns AS c ON c.id = a.campaign_id
             LEFT JOIN results AS r ON r.attempt_id = a.id
             WHERE ' . $where . '
             ORDER BY a.id ASC'
        );
        $statement->execute($parameters);

        $attempts = [];
        foreach ($statement->fetchAll() as $row) {
            $status = $this->rowString($row, 'status');
            if (!in_array($status, [Attempt::STATUS_STARTED, Attempt::STATUS_COMPLETED], true)) {
                throw new RuntimeException('Invalid persisted analytics attempt status.');
            }
            $attempts[] = [
                'attempt_id' => $this->rowPositiveInt($row, 'attempt_id'),
                'campaign_id' => $this->rowPositiveInt($row, 'campaign_id'),
                'campaign_batch_id' => $this->rowNullablePositiveInt($row, 'campaign_batch_id'),
                'quiz_version_id' => $this->rowPositiveInt($row, 'quiz_version_id'),
                'status' => $status,
                'result_id' => $this->rowNullablePositiveInt($row, 'result_id'),
                'is_tie' => $row['result_id'] === null ? null : $this->rowBool($row, 'is_tie'),
                'dominant_program_id' => $row['result_id'] === null ? null : $this->rowNullablePositiveInt($row, 'dominant_program_id'),
            ];
        }

        return $attempts;
    }

    /** @return list<array{id: int, campaign_id: int, batch_number: int, quiz_version_id: int, label: ?string, status: string}> */
    private function readBatches(int $schoolId, ?int $campaignId, ?int $batchId): array
    {
        $conditions = ['c.school_id = ?'];
        $parameters = [$schoolId];
        if ($campaignId !== null) {
            $conditions[] = 'cb.campaign_id = ?';
            $parameters[] = $campaignId;
        }
        if ($batchId !== null) {
            $conditions[] = 'cb.id = ?';
            $parameters[] = $batchId;
        }

        $statement = $this->connection()->prepare(
            'SELECT cb.id, cb.campaign_id, cb.batch_number, cb.quiz_version_id, cb.label, cb.status
             FROM campaign_batches AS cb
             INNER JOIN campaigns AS c ON c.id = cb.campaign_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY cb.campaign_id ASC, cb.batch_number ASC, cb.id ASC'
        );
        $statement->execute($parameters);

        $batches = [];
        foreach ($statement->fetchAll() as $row) {
            $status = $this->rowString($row, 'status');
            if (!in_array($status, [CampaignBatch::STATUS_ACTIVE, CampaignBatch::STATUS_CLOSED], true)) {
                throw new RuntimeException('Invalid persisted analytics batch status.');
            }
            $batches[] = [
                'id' => $this->rowPositiveInt($row, 'id'),
                'campaign_id' => $this->rowPositiveInt($row, 'campaign_id'),
                'batch_number' => $this->rowPositiveInt($row, 'batch_number'),
                'quiz_version_id' => $this->rowPositiveInt($row, 'quiz_version_id'),
                'label' => $this->rowNullableString($row, 'label'),
                'status' => $status,
            ];
        }

        return $batches;
    }

    /** @param list<int> $versionIds @return array<int, array<int, array{quiz_version_program_id: int, code: string, name: string}>> */
    private function readVersionMembers(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }
        [$in, $parameters] = $this->inClause($versionIds, 'version');
        $statement = $this->connection()->prepare(
            'SELECT qvp.id AS quiz_version_program_id, qvp.quiz_version_id, qvp.program_id,
                    qvpp.program_code_snapshot, qvpp.program_name_snapshot
             FROM quiz_version_programs AS qvp
             LEFT JOIN quiz_version_program_presentations AS qvpp
                ON qvpp.quiz_version_program_id = qvp.id
             WHERE qvp.quiz_version_id IN (' . $in . ')
             ORDER BY qvp.quiz_version_id ASC, qvp.program_id ASC'
        );
        $statement->execute($parameters);

        $members = [];
        foreach ($statement->fetchAll() as $row) {
            $versionId = $this->rowPositiveInt($row, 'quiz_version_id');
            $programId = $this->rowPositiveInt($row, 'program_id');
            if ($row['program_code_snapshot'] === null || $row['program_name_snapshot'] === null) {
                throw new RuntimeException('Persistence invariant violation: analytics program presentation snapshot is missing.');
            }
            if (isset($members[$versionId][$programId])) {
                throw new RuntimeException('Persistence invariant violation: duplicate analytics version program membership.');
            }
            $members[$versionId][$programId] = [
                'quiz_version_program_id' => $this->rowPositiveInt($row, 'quiz_version_program_id'),
                'code' => $this->rowString($row, 'program_code_snapshot'),
                'name' => $this->rowString($row, 'program_name_snapshot'),
            ];
        }

        foreach ($versionIds as $versionId) {
            if (!isset($members[$versionId]) || $members[$versionId] === []) {
                throw new RuntimeException('Persistence invariant violation: analytics quiz version has no program snapshots.');
            }
        }

        return $members;
    }

    /** @param list<int> $resultIds @return array<int, list<array{program_id: int, normalized_percentage: float}>> */
    private function readScores(array $resultIds): array
    {
        if ($resultIds === []) {
            return [];
        }
        [$in, $parameters] = $this->inClause($resultIds, 'result');
        $statement = $this->connection()->prepare(
            'SELECT result_id, program_id, normalized_percentage
             FROM result_scores
             WHERE result_id IN (' . $in . ')
             ORDER BY result_id ASC, display_order ASC'
        );
        $statement->execute($parameters);

        $scores = [];
        foreach ($statement->fetchAll() as $row) {
            $scores[$this->rowPositiveInt($row, 'result_id')][] = [
                'program_id' => $this->rowPositiveInt($row, 'program_id'),
                'normalized_percentage' => $this->rowFiniteFloat($row, 'normalized_percentage'),
            ];
        }

        return $scores;
    }

    /** @param list<int> $resultIds @return array<int, list<int>> */
    private function readTiedPrograms(array $resultIds): array
    {
        if ($resultIds === []) {
            return [];
        }
        [$in, $parameters] = $this->inClause($resultIds, 'tied_result');
        $statement = $this->connection()->prepare(
            'SELECT result_id, program_id
             FROM result_tied_programs
             WHERE result_id IN (' . $in . ')
             ORDER BY result_id ASC, program_id ASC'
        );
        $statement->execute($parameters);

        $programs = [];
        foreach ($statement->fetchAll() as $row) {
            $programs[$this->rowPositiveInt($row, 'result_id')][] = $this->rowPositiveInt($row, 'program_id');
        }

        return $programs;
    }

    /** @param array{quiz_version_id: int, is_tie: ?bool, dominant_program_id: ?int} $attempt @param array<int, array<int, array{quiz_version_program_id: int, code: string, name: string}>> $versionMembers */
    private function assertResultState(array $attempt, array $versionMembers): void
    {
        $members = $versionMembers[$attempt['quiz_version_id']] ?? null;
        if ($members === null) {
            throw new RuntimeException('Persistence invariant violation: analytics result version snapshot is missing.');
        }
        if ($attempt['is_tie'] === true && $attempt['dominant_program_id'] !== null) {
            throw new RuntimeException('Persistence invariant violation: tied analytics result has a dominant program.');
        }
        if ($attempt['is_tie'] === false && ($attempt['dominant_program_id'] === null || !isset($members[$attempt['dominant_program_id']]))) {
            throw new RuntimeException('Persistence invariant violation: decisive analytics result has an invalid dominant program.');
        }
    }

    /** @param array<int, array{quiz_version_id: int, is_tie: ?bool}> $results @param array<int, array<int, array{quiz_version_program_id: int, code: string, name: string}>> $versionMembers @param array<int, list<array{program_id: int, normalized_percentage: float}>> $scoresByResult @param array<int, list<int>> $tiedProgramsByResult */
    private function assertScoreAndTieInvariants(array $results, array $versionMembers, array $scoresByResult, array $tiedProgramsByResult): void
    {
        foreach ($results as $resultId => $attempt) {
            $members = $versionMembers[$attempt['quiz_version_id']];
            $scores = $scoresByResult[$resultId] ?? [];
            $scoreProgramIds = [];
            foreach ($scores as $score) {
                if (!isset($members[$score['program_id']]) || isset($scoreProgramIds[$score['program_id']])) {
                    throw new RuntimeException('Persistence invariant violation: analytics result score membership is invalid.');
                }
                $scoreProgramIds[$score['program_id']] = true;
            }
            if (count($scoreProgramIds) !== count($members) || array_diff_key($members, $scoreProgramIds) !== []) {
                throw new RuntimeException('Persistence invariant violation: analytics result scores are incomplete.');
            }

            $tiedPrograms = $tiedProgramsByResult[$resultId] ?? [];
            if ($attempt['is_tie'] === true) {
                if (count($tiedPrograms) < 2 || count(array_unique($tiedPrograms)) !== count($tiedPrograms)) {
                    throw new RuntimeException('Persistence invariant violation: analytics tie membership is invalid.');
                }
                foreach ($tiedPrograms as $programId) {
                    if (!isset($members[$programId])) {
                        throw new RuntimeException('Persistence invariant violation: analytics tied program is outside the version snapshot.');
                    }
                }
            } elseif ($tiedPrograms !== []) {
                throw new RuntimeException('Persistence invariant violation: decisive analytics result has tied programs.');
            }
        }
    }

    /** @param list<array{attempt_id: int, campaign_id: int, campaign_batch_id: ?int, quiz_version_id: int, status: string, result_id: ?int, is_tie: ?bool, dominant_program_id: ?int}> $attempts @param list<array{id: int, campaign_id: int, batch_number: int, quiz_version_id: int, label: ?string, status: string}> $batches @param array<int, array<int, array{quiz_version_program_id: int, code: string, name: string}>> $versionMembers @param array<int, list<array{program_id: int, normalized_percentage: float}>> $scoresByResult */
    private function project(array $attempts, array $batches, array $versionMembers, array $scoresByResult): array
    {
        $batchMetrics = [];
        $campaignMetrics = [];
        foreach ($batches as $batch) {
            $batchMetrics[$batch['id']] = $this->emptyMetrics();
            $campaignMetrics[$batch['campaign_id']] ??= $this->emptyMetrics();
        }
        $summary = $this->emptyMetrics();
        $cohorts = [];

        foreach ($versionMembers as $versionId => $members) {
            foreach ($members as $programId => $member) {
                $cohorts[$versionId][$programId] = [
                    'quiz_version_id' => $versionId,
                    'program_id' => $programId,
                    'snapshot_identity' => $member['quiz_version_program_id'],
                    'program_code' => $member['code'],
                    'program_name' => $member['name'],
                    'dominant_count' => 0,
                    'score_row_count' => 0,
                    'score_percentage_sum' => 0.0,
                ];
            }
        }

        foreach ($attempts as $attempt) {
            $this->incrementStarted($summary);
            if ($attempt['campaign_batch_id'] !== null && isset($batchMetrics[$attempt['campaign_batch_id']])) {
                $this->incrementStarted($batchMetrics[$attempt['campaign_batch_id']]);
            }
            if (isset($campaignMetrics[$attempt['campaign_id']])) {
                $this->incrementStarted($campaignMetrics[$attempt['campaign_id']]);
            }
            if ($attempt['result_id'] === null) {
                continue;
            }

            $this->incrementCompleted($summary, $attempt['is_tie'] === true);
            if ($attempt['campaign_batch_id'] !== null && isset($batchMetrics[$attempt['campaign_batch_id']])) {
                $this->incrementCompleted($batchMetrics[$attempt['campaign_batch_id']], $attempt['is_tie'] === true);
            }
            if (isset($campaignMetrics[$attempt['campaign_id']])) {
                $this->incrementCompleted($campaignMetrics[$attempt['campaign_id']], $attempt['is_tie'] === true);
            }

            foreach ($scoresByResult[$attempt['result_id']] as $score) {
                $cohorts[$attempt['quiz_version_id']][$score['program_id']]['score_row_count']++;
                $cohorts[$attempt['quiz_version_id']][$score['program_id']]['score_percentage_sum'] += $score['normalized_percentage'];
            }
            if ($attempt['is_tie'] === false) {
                $cohorts[$attempt['quiz_version_id']][$attempt['dominant_program_id']]['dominant_count']++;
            }
        }

        $batchTrends = [];
        foreach ($batches as $batch) {
            $batchTrends[] = [
                'batch_id' => $batch['id'],
                'campaign_id' => $batch['campaign_id'],
                'batch_number' => $batch['batch_number'],
                'batch_label' => $batch['label'],
                'batch_status' => $batch['status'],
                'quiz_version_id' => $batch['quiz_version_id'],
                ...$this->publicMetrics($batchMetrics[$batch['id']]),
            ];
        }
        $campaignTrends = [];
        foreach ($campaignMetrics as $campaignId => $metrics) {
            $campaignTrends[] = ['campaign_id' => $campaignId, ...$this->publicMetrics($metrics)];
        }
        ksort($campaignTrends);

        $programCohorts = [];
        foreach ($cohorts as $programs) {
            foreach ($programs as $cohort) {
                $programCohorts[] = [
                    'quiz_version_id' => $cohort['quiz_version_id'],
                    'program_id' => $cohort['program_id'],
                    'snapshot_identity' => $cohort['snapshot_identity'],
                    'program_code' => $cohort['program_code'],
                    'program_name' => $cohort['program_name'],
                    'dominant_count' => $cohort['dominant_count'],
                    'score_average_percentage' => $cohort['score_row_count'] === 0 ? null : $cohort['score_percentage_sum'] / $cohort['score_row_count'],
                    'score_average_denominator' => $cohort['score_row_count'],
                ];
            }
        }
        usort($programCohorts, static fn (array $left, array $right): int => [$left['quiz_version_id'], $left['program_code'], $left['program_id']] <=> [$right['quiz_version_id'], $right['program_code'], $right['program_id']]);

        $comparison = [];
        foreach ($batches as $batch) {
            $comparison[$batch['quiz_version_id']]['quiz_version_id'] = $batch['quiz_version_id'];
            $comparison[$batch['quiz_version_id']]['batch_ids'][] = $batch['id'];
            $comparison[$batch['quiz_version_id']]['program_count'] = count($versionMembers[$batch['quiz_version_id']]);
        }

        return [
            'summary' => $this->publicMetrics($summary),
            'batch_trends' => $batchTrends,
            'campaign_trends' => array_values($campaignTrends),
            'program_cohorts' => $programCohorts,
            'comparison_cohorts' => array_values($comparison),
        ];
    }

    /** @return array{started_attempt_count: int, completed_result_count: int, decisive_result_count: int, tie_count: int} */
    private function emptyMetrics(): array
    {
        return ['started_attempt_count' => 0, 'completed_result_count' => 0, 'decisive_result_count' => 0, 'tie_count' => 0];
    }

    /** @param array{started_attempt_count: int, completed_result_count: int, decisive_result_count: int, tie_count: int} $metrics */
    private function incrementStarted(array &$metrics): void
    {
        $metrics['started_attempt_count']++;
    }

    /** @param array{started_attempt_count: int, completed_result_count: int, decisive_result_count: int, tie_count: int} $metrics */
    private function incrementCompleted(array &$metrics, bool $isTie): void
    {
        $metrics['completed_result_count']++;
        if ($isTie) {
            $metrics['tie_count']++;
            return;
        }
        $metrics['decisive_result_count']++;
    }

    /** @param array{started_attempt_count: int, completed_result_count: int, decisive_result_count: int, tie_count: int} $metrics @return array{started_attempt_count: int, completed_result_count: int, decisive_result_count: int, tie_count: int, tie_rate: array{numerator: int, denominator: int, rate: ?float}} */
    private function publicMetrics(array $metrics): array
    {
        return [
            ...$metrics,
            'tie_rate' => [
                'numerator' => $metrics['tie_count'],
                'denominator' => $metrics['completed_result_count'],
                'rate' => $metrics['completed_result_count'] === 0 ? null : $metrics['tie_count'] / $metrics['completed_result_count'],
            ],
        ];
    }

    /** @param list<array{quiz_version_id: int}> $attempts @param list<array{quiz_version_id: int}> $batches @return list<int> */
    private function versionIds(array $attempts, array $batches): array
    {
        $ids = [];
        foreach ([$attempts, $batches] as $rows) {
            foreach ($rows as $row) {
                $ids[$row['quiz_version_id']] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /** @param list<int> $values @return array{0: string, 1: array<string, int>} */
    private function inClause(array $values, string $prefix): array
    {
        $placeholders = [];
        $parameters = [];
        foreach ($values as $index => $value) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $value;
        }

        return [implode(', ', $placeholders), $parameters];
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
        $value = $this->rowNonNegativeInt($row, $key);
        if ($value < 1) {
            throw new RuntimeException('Invalid persisted analytics data.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function rowNullablePositiveInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('Invalid persisted analytics data.');
        }
        if ($row[$key] === null) {
            return null;
        }

        return $this->rowPositiveInt($row, $key);
    }

    /** @param array<string, mixed> $row */
    private function rowNonNegativeInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || (int) $row[$key] < 0) {
            throw new RuntimeException('Invalid persisted analytics data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key]) || trim($row[$key]) === '') {
            throw new RuntimeException('Invalid persisted analytics data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid persisted analytics data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowBool(array $row, string $key): bool
    {
        $value = $this->rowNonNegativeInt($row, $key);
        if ($value !== 0 && $value !== 1) {
            throw new RuntimeException('Invalid persisted analytics data.');
        }

        return $value === 1;
    }

    /** @param array<string, mixed> $row */
    private function rowFiniteFloat(array $row, string $key): float
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || !is_finite((float) $row[$key])) {
            throw new RuntimeException('Invalid persisted analytics data.');
        }

        return (float) $row[$key];
    }
}
