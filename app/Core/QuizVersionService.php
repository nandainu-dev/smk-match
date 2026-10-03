<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class QuizVersionService
{
    public function __construct(private readonly QuizVersionRepository $repository)
    {
    }

    public function getOrCreateDraft(
        int $quizId,
        QuizDefinition $initialSnapshot,
    ): QuizVersion {
        $this->assertPositiveId($quizId, 'Quiz identity');

        $draft = $this->repository->findDraftForQuiz($quizId);
        if ($draft !== null) {
            if ($draft->quizId !== $quizId) {
                throw new RuntimeException('Persistence invariant violation: draft belongs to another quiz.');
            }

            if (!$draft->isDraft() || !$draft->isEditable()) {
                throw new RuntimeException('Persistence invariant violation: editable draft has an invalid lifecycle state.');
            }

            $this->assertUnused($draft);

            return $draft;
        }

        return $this->repository->createDraftSnapshot($quizId, $initialSnapshot);
    }

    /** @param list<int> $selectedProgramIds */
    public function getOrCreateDraftForProgramIds(
        int $quizId,
        QuizDefinition $initialSnapshot,
        array $selectedProgramIds,
    ): QuizVersion {
        $this->assertPositiveId($quizId, 'Quiz identity');

        $draft = $this->repository->findDraftForQuiz($quizId);
        if ($draft !== null) {
            if ($draft->quizId !== $quizId || !$draft->isDraft() || !$draft->isEditable()) {
                throw new RuntimeException('Persistence invariant violation: editable draft has an invalid lifecycle state.');
            }

            $this->assertUnused($draft);

            return $draft;
        }

        return $this->repository->createDraftSnapshotForProgramIds($quizId, $initialSnapshot, $selectedProgramIds);
    }

    public function cloneVersionToDraft(
        int $quizId,
        int $sourceVersionId,
    ): QuizVersion {
        $this->assertPositiveId($quizId, 'Quiz identity');
        $this->assertPositiveId($sourceVersionId, 'Source quiz version identity');

        $source = $this->requireVersionForQuiz($quizId, $sourceVersionId);
        $existingDraft = $this->repository->findDraftForQuiz($quizId);

        if ($existingDraft !== null) {
            if ($existingDraft->quizId !== $quizId) {
                throw new RuntimeException('Persistence invariant violation: draft belongs to another quiz.');
            }

            throw new RuntimeException('An editable draft already exists for this quiz.');
        }

        return $this->repository->createDraftSnapshot($quizId, $source->definition(), $sourceVersionId);
    }

    public function cloneVersionToDraftWithCurrentPresentations(
        int $quizId,
        int $sourceVersionId,
    ): QuizVersion {
        $this->assertPositiveId($quizId, 'Quiz identity');
        $this->assertPositiveId($sourceVersionId, 'Source quiz version identity');

        $source = $this->requireVersionForQuiz($quizId, $sourceVersionId);
        $existingDraft = $this->repository->findDraftForQuiz($quizId);

        if ($existingDraft !== null) {
            if ($existingDraft->quizId !== $quizId) {
                throw new RuntimeException('Persistence invariant violation: draft belongs to another quiz.');
            }

            throw new RuntimeException('An editable draft already exists for this quiz.');
        }

        return $this->repository->createDraftSnapshotFromSourceWithCurrentPresentations(
            $quizId,
            $source->definition(),
            $sourceVersionId,
        );
    }

    public function publish(
        int $quizId,
        int $versionId,
        ?DateTimeImmutable $publishedAt = null,
    ): QuizVersion {
        $this->assertPositiveId($quizId, 'Quiz identity');
        $this->assertPositiveId($versionId, 'Quiz version identity');

        $version = $this->requireVersionForQuiz($quizId, $versionId);
        $this->assertDraft($version, 'published');
        $this->assertUnused($version);

        $timestamp = $publishedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $updated = $this->repository->updateStatus(
            $versionId,
            QuizVersion::STATUS_DRAFT,
            QuizVersion::STATUS_PUBLISHED,
            $timestamp,
        );

        if ($updated !== true) {
            throw new RuntimeException('Quiz version lifecycle state changed concurrently.');
        }

        return $this->reloadExpectedStatus($versionId, QuizVersion::STATUS_PUBLISHED);
    }

    public function discard(int $quizId, int $versionId): QuizVersion
    {
        $this->assertPositiveId($quizId, 'Quiz identity');
        $this->assertPositiveId($versionId, 'Quiz version identity');

        $version = $this->requireVersionForQuiz($quizId, $versionId);
        $this->assertDraft($version, 'discarded');
        $this->assertUnused($version);

        $updated = $this->repository->updateStatus(
            $versionId,
            QuizVersion::STATUS_DRAFT,
            QuizVersion::STATUS_DISCARDED,
            null,
        );

        if ($updated !== true) {
            throw new RuntimeException('Quiz version lifecycle state changed concurrently.');
        }

        return $this->reloadExpectedStatus($versionId, QuizVersion::STATUS_DISCARDED);
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException($label . ' must be positive.');
        }
    }

    private function requireVersionForQuiz(int $quizId, int $versionId): QuizVersion
    {
        $version = $this->repository->findById($versionId);
        if ($version === null) {
            throw new RuntimeException('Quiz version does not exist.');
        }

        if ($version->quizId !== $quizId) {
            throw new RuntimeException('Quiz version does not belong to the requested quiz.');
        }

        return $version;
    }

    private function assertDraft(QuizVersion $version, string $operation): void
    {
        if (!$version->isDraft()) {
            throw new RuntimeException('Only draft quiz versions can be ' . $operation . '.');
        }
    }

    private function assertUnused(QuizVersion $version): void
    {
        if ($this->repository->hasPersistedUsage($version->id)) {
            throw new RuntimeException('Quiz versions with persisted usage are immutable.');
        }
    }

    private function reloadExpectedStatus(int $versionId, string $expectedStatus): QuizVersion
    {
        $version = $this->repository->findById($versionId);
        if ($version === null) {
            throw new RuntimeException('Persistence invariant violation: updated quiz version could not be reloaded.');
        }

        if ($version->status !== $expectedStatus) {
            throw new RuntimeException('Persistence invariant violation: quiz version status did not update.');
        }

        return $version;
    }
}
