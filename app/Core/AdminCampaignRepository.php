<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class AdminCampaignRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return list<Campaign> */
    public function listCampaignsForSchool(int $schoolId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');

        $statement = $this->connection()->prepare(
            'SELECT id, school_id, quiz_id, quiz_version_id, name, status, starts_at, ends_at, created_at, updated_at
             FROM campaigns
             WHERE school_id = :school_id
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['school_id' => $schoolId]);

        return array_map(fn (array $row): Campaign => $this->campaign($row), $statement->fetchAll());
    }

    public function findCampaignForSchool(int $schoolId, int $campaignId): ?Campaign
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($campaignId, 'Campaign identity');

        $statement = $this->connection()->prepare(
            'SELECT id, school_id, quiz_id, quiz_version_id, name, status, starts_at, ends_at, created_at, updated_at
             FROM campaigns
             WHERE id = :campaign_id
               AND school_id = :school_id'
        );
        $statement->execute([
            'campaign_id' => $campaignId,
            'school_id' => $schoolId,
        ]);
        $row = $statement->fetch();

        return $row === false ? null : $this->campaign($row);
    }

    /** @return list<CampaignBatch> */
    public function listBatchesForCampaign(int $schoolId, int $campaignId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($campaignId, 'Campaign identity');

        $statement = $this->connection()->prepare(
            'SELECT cb.id, cb.campaign_id, cb.batch_number, cb.quiz_version_id, cb.label,
                    cb.status, cb.active_marker, cb.started_at, cb.closed_at, cb.created_at, cb.updated_at
             FROM campaign_batches AS cb
             INNER JOIN campaigns AS c ON c.id = cb.campaign_id
             WHERE cb.campaign_id = :campaign_id
               AND c.school_id = :school_id
             ORDER BY cb.batch_number ASC, cb.id ASC'
        );
        $statement->execute([
            'campaign_id' => $campaignId,
            'school_id' => $schoolId,
        ]);

        return array_map(fn (array $row): CampaignBatch => $this->batch($row), $statement->fetchAll());
    }

    public function findBatchForCampaign(int $schoolId, int $campaignId, int $batchId): ?CampaignBatch
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($campaignId, 'Campaign identity');
        $this->assertPositiveId($batchId, 'Campaign batch identity');

        $statement = $this->connection()->prepare(
            'SELECT cb.id, cb.campaign_id, cb.batch_number, cb.quiz_version_id, cb.label,
                    cb.status, cb.active_marker, cb.started_at, cb.closed_at, cb.created_at, cb.updated_at
             FROM campaign_batches AS cb
             INNER JOIN campaigns AS c ON c.id = cb.campaign_id
             WHERE cb.id = :batch_id
               AND cb.campaign_id = :campaign_id
               AND c.school_id = :school_id'
        );
        $statement->execute([
            'batch_id' => $batchId,
            'campaign_id' => $campaignId,
            'school_id' => $schoolId,
        ]);
        $row = $statement->fetch();

        return $row === false ? null : $this->batch($row);
    }

    /** @return list<array{id: int, version_number: int, name: string, status: string}> */
    public function listPublishedQuizVersionsForCampaign(int $schoolId, int $campaignId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($campaignId, 'Campaign identity');

        $statement = $this->connection()->prepare(
            'SELECT qv.id, qv.version_number, qv.name, qv.status
             FROM quiz_versions AS qv
             INNER JOIN quizzes AS q ON q.id = qv.quiz_id
             INNER JOIN campaigns AS c ON c.quiz_id = q.id
             WHERE c.id = :campaign_id
               AND c.school_id = :campaign_school_id
               AND q.school_id = :quiz_school_id
               AND qv.status = :published_status
             ORDER BY qv.version_number DESC, qv.id DESC'
        );
        $statement->execute([
            'campaign_id' => $campaignId,
            'campaign_school_id' => $schoolId,
            'quiz_school_id' => $schoolId,
            'published_status' => QuizVersion::STATUS_PUBLISHED,
        ]);

        return array_map(fn (array $row): array => [
            'id' => $this->positiveInt($row, 'id'),
            'version_number' => $this->positiveInt($row, 'version_number'),
            'name' => $this->string($row, 'name'),
            'status' => $this->string($row, 'status'),
        ], $statement->fetchAll());
    }

    /** @return list<SmartLink> */
    public function listSmartLinksForCampaign(int $schoolId, int $campaignId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($campaignId, 'Campaign identity');

        $statement = $this->connection()->prepare(
            'SELECT sl.id, sl.school_id, sl.campaign_id, sl.name, sl.alias, sl.source,
                    sl.qr_target_url, sl.is_active, sl.scan_count, sl.created_at, sl.updated_at
             FROM smart_links AS sl
             INNER JOIN campaigns AS c ON c.id = sl.campaign_id
             WHERE sl.campaign_id = :campaign_id
               AND sl.school_id = :smart_link_school_id
               AND c.school_id = :campaign_school_id
             ORDER BY sl.created_at ASC, sl.id ASC'
        );
        $statement->execute([
            'campaign_id' => $campaignId,
            'smart_link_school_id' => $schoolId,
            'campaign_school_id' => $schoolId,
        ]);

        return array_map(fn (array $row): SmartLink => $this->smartLink($row), $statement->fetchAll());
    }

    public function findSmartLinkForCampaign(int $schoolId, int $campaignId, string $alias): ?SmartLink
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($campaignId, 'Campaign identity');
        $canonicalAlias = SmartLink::canonicalAlias($alias);

        $statement = $this->connection()->prepare(
            'SELECT sl.id, sl.school_id, sl.campaign_id, sl.name, sl.alias, sl.source,
                    sl.qr_target_url, sl.is_active, sl.scan_count, sl.created_at, sl.updated_at
             FROM smart_links AS sl
             INNER JOIN campaigns AS c ON c.id = sl.campaign_id
             WHERE sl.school_id = :smart_link_school_id
               AND c.school_id = :campaign_school_id
               AND sl.campaign_id = :campaign_id
               AND sl.alias = :alias'
        );
        $statement->execute([
            'smart_link_school_id' => $schoolId,
            'campaign_school_id' => $schoolId,
            'campaign_id' => $campaignId,
            'alias' => $canonicalAlias,
        ]);
        $row = $statement->fetch();

        return $row === false ? null : $this->smartLink($row);
    }

    public function saveSmartLinkQrTarget(int $schoolId, int $campaignId, string $alias, string $target): SmartLink
    {
        $link = $this->findSmartLinkForCampaign($schoolId, $campaignId, $alias);
        if ($link === null) {
            throw new RuntimeException('Smart link was not found.');
        }

        $statement = $this->connection()->prepare(
            'UPDATE smart_links
             SET qr_target_url = :target,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND school_id = :school_id
               AND campaign_id = :campaign_id'
        );
        $statement->execute([
            'target' => $target,
            'id' => $link->id,
            'school_id' => $schoolId,
            'campaign_id' => $campaignId,
        ]);

        $saved = $this->findSmartLinkForCampaign($schoolId, $campaignId, $link->alias);
        if ($saved === null) {
            throw new RuntimeException('Smart link QR target could not be reloaded.');
        }

        return $saved;
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }

    /** @param array<string, mixed> $row */
    private function campaign(array $row): Campaign
    {
        return new Campaign(
            $this->positiveInt($row, 'id'),
            $this->positiveInt($row, 'school_id'),
            $this->nullablePositiveInt($row, 'quiz_id'),
            $this->nullablePositiveInt($row, 'quiz_version_id'),
            $this->string($row, 'name'),
            $this->string($row, 'status'),
            $this->nullableString($row, 'starts_at'),
            $this->nullableString($row, 'ends_at'),
            $this->string($row, 'created_at'),
            $this->string($row, 'updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function batch(array $row): CampaignBatch
    {
        return new CampaignBatch(
            $this->positiveInt($row, 'id'),
            $this->positiveInt($row, 'campaign_id'),
            $this->positiveInt($row, 'batch_number'),
            $this->positiveInt($row, 'quiz_version_id'),
            $this->nullableString($row, 'label'),
            $this->string($row, 'status'),
            $this->nullableInt($row, 'active_marker'),
            $this->string($row, 'started_at'),
            $this->nullableString($row, 'closed_at'),
            $this->string($row, 'created_at'),
            $this->string($row, 'updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function smartLink(array $row): SmartLink
    {
        return new SmartLink(
            $this->positiveInt($row, 'id'),
            $this->positiveInt($row, 'school_id'),
            $this->nullablePositiveInt($row, 'campaign_id'),
            $this->string($row, 'name'),
            $this->string($row, 'alias'),
            $this->nullableString($row, 'source'),
            $this->nullableString($row, 'qr_target_url'),
            $this->bool($row, 'is_active'),
            $this->nonNegativeInt($row, 'scan_count'),
            $this->string($row, 'created_at'),
            $this->string($row, 'updated_at'),
        );
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
            throw new RuntimeException('Invalid persisted admin campaign data.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nonNegativeInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || (int) $row[$key] < 0) {
            throw new RuntimeException('Invalid persisted admin campaign data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function nullablePositiveInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('Invalid persisted admin campaign data.');
        }
        if ($row[$key] === null) {
            return null;
        }

        return $this->positiveInt($row, $key);
    }

    /** @param array<string, mixed> $row */
    private function nullableInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('Invalid persisted admin campaign data.');
        }
        if ($row[$key] === null) {
            return null;
        }

        return $this->nonNegativeInt($row, $key);
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key]) || trim($row[$key]) === '') {
            throw new RuntimeException('Invalid persisted admin campaign data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid persisted admin campaign data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function bool(array $row, string $key): bool
    {
        $value = $this->nonNegativeInt($row, $key);
        if ($value !== 0 && $value !== 1) {
            throw new RuntimeException('Invalid persisted admin campaign data.');
        }

        return $value === 1;
    }
}
