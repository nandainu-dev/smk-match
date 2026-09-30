<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class ParticipantStartService
{
    public function __construct(
        private readonly Database $database,
        private readonly AttemptRepository $attempts,
        private readonly ParticipantRepository $participants,
        private readonly CampaignRepository $campaigns,
        private readonly CampaignBatchRepository $batches,
        private readonly QuizVersionRepository $quizVersions,
        private readonly UuidV4Generator $uuidGenerator,
    ) {
    }

    public function start(
        int $campaignId,
        string $visitorUuid,
        string $attemptUuid,
        string $fullName,
        ?string $originSchool,
        ?string $className,
        ?string $phone,
        bool $marketingConsent,
        DateTimeImmutable $startedAt,
    ): ParticipantStartOutcome {
        if ($campaignId < 1) {
            throw new \InvalidArgumentException('Campaign identity must be positive.');
        }

        if (trim($visitorUuid) === '' || trim($attemptUuid) === '') {
            throw new \InvalidArgumentException('Visitor and attempt UUIDs must not be blank.');
        }

        $connection = $this->connection();
        if ($connection->inTransaction()) {
            throw new \LogicException('Participant start service does not support nested transactions.');
        }

        $connection->beginTransaction();

        try {
            $existingAttempt = $this->attempts->findByAttemptUuidForUpdate($attemptUuid);
            if ($existingAttempt !== null) {
                $outcome = $this->existingOutcome($existingAttempt, $campaignId, $visitorUuid);
                $connection->commit();

                return $outcome;
            }

            $campaign = $this->campaigns->findByIdForUpdate($campaignId);
            if ($campaign === null) {
                throw new RuntimeException('Campaign does not exist.');
            }

            if ($campaign->status !== 'active') {
                throw new RuntimeException('Campaign is not startable.');
            }

            $activeBatch = $this->batches->findActiveByCampaignIdForUpdate($campaign->id);
            if ($activeBatch === null || $activeBatch->status !== CampaignBatch::STATUS_ACTIVE) {
                throw new RuntimeException('Campaign has no valid active batch.');
            }

            if ($activeBatch->campaignId !== $campaign->id) {
                throw new RuntimeException('Active batch does not belong to the requested campaign.');
            }

            $quizVersion = $this->quizVersions->findById($activeBatch->quizVersionId);
            if ($quizVersion === null || $campaign->quizId === null || $quizVersion->quizId !== $campaign->quizId) {
                throw new RuntimeException('Active batch quiz version does not belong to the campaign quiz.');
            }

            if (!$quizVersion->isPublished()) {
                throw new RuntimeException('Active batch quiz version must be published.');
            }

            $participant = $this->participants->create(
                $campaign->schoolId,
                $this->uuidGenerator->generate(),
                $fullName,
                $originSchool,
                $className,
                $phone,
                $marketingConsent,
                $marketingConsent ? $startedAt : null,
                $startedAt,
                $startedAt,
            );
            $attempt = $this->attempts->create(
                $participant->id,
                $campaign->id,
                $activeBatch->id,
                $activeBatch->quizVersionId,
                $visitorUuid,
                $attemptUuid,
                null,
                $startedAt,
            );

            $connection->commit();

            return new ParticipantStartOutcome(
                $participant,
                $attempt,
                $activeBatch,
                $activeBatch->quizVersionId,
                false,
            );
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }
    }

    private function existingOutcome(Attempt $attempt, int $requestedCampaignId, string $requestedVisitorUuid): ParticipantStartOutcome
    {
        if ($attempt->campaignId !== $requestedCampaignId || $attempt->visitorUuid !== $requestedVisitorUuid) {
            throw new RuntimeException('Attempt UUID conflicts with the requested start identity.');
        }

        if ($attempt->participantId === null || $attempt->campaignId === null || $attempt->campaignBatchId === null) {
            throw new RuntimeException('Existing attempt does not have a valid start binding.');
        }

        $participant = $this->participants->findById($attempt->participantId);
        $campaign = $this->campaigns->findById($attempt->campaignId);
        $batch = $this->batches->findById($attempt->campaignBatchId);
        $quizVersion = $this->quizVersions->findById($attempt->quizVersionId);

        if ($participant === null || $campaign === null || $batch === null || $quizVersion === null) {
            throw new RuntimeException('Existing attempt start context does not exist.');
        }

        if ($attempt->campaignId !== $batch->campaignId
            || $attempt->quizVersionId !== $batch->quizVersionId
            || $participant->schoolId !== $campaign->schoolId
            || $campaign->quizId === null
            || $quizVersion->quizId !== $campaign->quizId) {
            throw new RuntimeException('Existing attempt start context is inconsistent.');
        }

        return new ParticipantStartOutcome(
            $participant,
            $attempt,
            $batch,
            $attempt->quizVersionId,
            true,
        );
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }
}
