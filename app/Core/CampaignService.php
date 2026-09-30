<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class CampaignService
{
    private const STATUS_DRAFT = 'draft';
    private const STATUS_ACTIVE = 'active';

    public function __construct(
        private readonly Database $database,
        private readonly CampaignRepository $campaignRepository,
        private readonly CampaignBatchRepository $campaignBatchRepository,
        private readonly QuizVersionRepository $quizVersionRepository,
    ) {
    }

    public function activateCampaign(
        int $campaignId,
        ?DateTimeImmutable $timestamp = null,
    ): CampaignBatch {
        $this->assertPositiveId($campaignId, 'Campaign identity');

        return $this->withinTransaction(function (DateTimeImmutable $utcTimestamp) use ($campaignId): CampaignBatch {
            $campaign = $this->requireLockedCampaign($campaignId);

            if ($campaign->status !== self::STATUS_DRAFT) {
                throw new RuntimeException('Only draft campaigns can be activated.');
            }

            $quizVersionId = $this->requireConfiguredPublishedVersion($campaign);
            $activeBatch = $this->campaignBatchRepository->findActiveByCampaignIdForUpdate($campaign->id);
            if ($activeBatch !== null) {
                throw new RuntimeException('Campaign already has an active batch.');
            }

            $nextBatchNumber = $this->campaignBatchRepository->getMaxBatchNumber($campaign->id) + 1;
            $batch = $this->campaignBatchRepository->create(
                $campaign->id,
                $nextBatchNumber,
                $quizVersionId,
                null,
                CampaignBatch::STATUS_ACTIVE,
                1,
                $utcTimestamp,
                null,
            );
            $activated = $this->campaignRepository->updateStatus(
                $campaign->id,
                self::STATUS_DRAFT,
                self::STATUS_ACTIVE,
            );
            if (!$activated) {
                throw new RuntimeException('Campaign lifecycle state changed concurrently.');
            }

            return $batch;
        }, $timestamp);
    }

    public function resetActiveBatch(
        int $campaignId,
        ?DateTimeImmutable $timestamp = null,
    ): CampaignBatch {
        $this->assertPositiveId($campaignId, 'Campaign identity');

        return $this->withinTransaction(function (DateTimeImmutable $utcTimestamp) use ($campaignId): CampaignBatch {
            $campaign = $this->requireLockedCampaign($campaignId);

            if ($campaign->status !== self::STATUS_ACTIVE) {
                throw new RuntimeException('Only active campaigns can reset their active batch.');
            }

            $quizVersionId = $this->requireConfiguredPublishedVersion($campaign);
            $activeBatch = $this->campaignBatchRepository->findActiveByCampaignIdForUpdate($campaign->id);
            if ($activeBatch === null) {
                throw new RuntimeException('Campaign has no active batch to reset.');
            }

            if ($activeBatch->status !== CampaignBatch::STATUS_ACTIVE) {
                throw new RuntimeException('Persistence invariant violation: active batch marker has an invalid status.');
            }

            $nextBatchNumber = $this->campaignBatchRepository->getMaxBatchNumber($campaign->id) + 1;
            if (!$this->campaignBatchRepository->close($activeBatch->id, $utcTimestamp)) {
                throw new RuntimeException('Active campaign batch changed concurrently.');
            }

            return $this->campaignBatchRepository->create(
                $campaign->id,
                $nextBatchNumber,
                $quizVersionId,
                null,
                CampaignBatch::STATUS_ACTIVE,
                1,
                $utcTimestamp,
                null,
            );
        }, $timestamp);
    }

    private function requireLockedCampaign(int $campaignId): Campaign
    {
        $campaign = $this->campaignRepository->findByIdForUpdate($campaignId);
        if ($campaign === null) {
            throw new RuntimeException('Campaign does not exist.');
        }

        return $campaign;
    }

    private function requireConfiguredPublishedVersion(Campaign $campaign): int
    {
        if ($campaign->quizId === null) {
            throw new RuntimeException('Campaign has no configured quiz context.');
        }

        if ($campaign->quizVersionId === null) {
            throw new RuntimeException('Campaign has no configured quiz version.');
        }

        $version = $this->quizVersionRepository->findById($campaign->quizVersionId);
        if ($version === null) {
            throw new RuntimeException('Configured quiz version does not exist.');
        }

        if ($version->quizId !== $campaign->quizId) {
            throw new RuntimeException('Configured quiz version does not belong to the campaign quiz.');
        }

        if (!$version->isPublished()) {
            throw new RuntimeException('Configured quiz version must be published.');
        }

        if ($this->quizSchoolId($version->quizId) !== $campaign->schoolId) {
            throw new RuntimeException('Configured quiz version does not belong to the campaign school.');
        }

        return $version->id;
    }

    private function quizSchoolId(int $quizId): int
    {
        $statement = $this->connection()->prepare(
            'SELECT school_id
             FROM quizzes
             WHERE id = :id'
        );
        $statement->execute(['id' => $quizId]);
        $schoolId = $statement->fetchColumn();

        if (!is_numeric($schoolId)) {
            throw new RuntimeException('Configured quiz does not exist.');
        }

        return (int) $schoolId;
    }

    /** @param callable(DateTimeImmutable): CampaignBatch $operation */
    private function withinTransaction(callable $operation, ?DateTimeImmutable $timestamp): CampaignBatch
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $resolvedTimestamp = ($timestamp ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
            $batch = $operation($resolvedTimestamp);
            $connection->commit();

            return $batch;
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
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
}
