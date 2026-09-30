<?php
declare(strict_types=1);

namespace App\Core;

use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class ParticipantQuizDeliveryService
{
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly ParticipantRepository $participants,
        private readonly CampaignRepository $campaigns,
        private readonly CampaignBatchRepository $batches,
        private readonly QuizVersionRepository $quizVersions,
        private readonly VisitorIdentityCookie $visitorCookie,
    ) {
    }

    /**
     * @return array{attempt_uuid: string, status: string, quiz: array{name: string, questions: list<array{id: string, text: string, order: int, help_text?: string, image_path?: string, options: list<array{id: string, text: string, order: int}>}>}}
     */
    public function deliver(string $attemptUuid, string $visitorUuid): array
    {
        if (!$this->visitorCookie->isValidUuidV4($attemptUuid)
            || !$this->visitorCookie->isValidUuidV4($visitorUuid)) {
            throw new InvalidArgumentException('Attempt delivery does not exist.');
        }

        $attempt = $this->attempts->findByAttemptUuid($attemptUuid);
        if ($attempt === null || !hash_equals($attempt->visitorUuid, $visitorUuid)) {
            throw new InvalidArgumentException('Attempt delivery does not exist.');
        }

        if ($attempt->status === Attempt::STATUS_COMPLETED) {
            throw new DomainException('Attempt has already been completed.');
        }

        if ($attempt->status !== Attempt::STATUS_STARTED) {
            throw new RuntimeException('Attempt has an unsupported delivery status.');
        }

        if ($attempt->participantId === null
            || $attempt->campaignId === null
            || $attempt->campaignBatchId === null) {
            throw new RuntimeException('Attempt delivery bindings are incomplete.');
        }

        $participant = $this->participants->findById($attempt->participantId);
        $campaign = $this->campaigns->findById($attempt->campaignId);
        $batch = $this->batches->findById($attempt->campaignBatchId);
        $quizVersion = $this->quizVersions->findById($attempt->quizVersionId);

        if ($participant === null || $campaign === null || $batch === null || $quizVersion === null) {
            throw new RuntimeException('Attempt delivery context does not exist.');
        }

        if ($batch->campaignId !== $attempt->campaignId
            || $batch->quizVersionId !== $attempt->quizVersionId
            || $participant->schoolId !== $campaign->schoolId
            || $campaign->quizId === null
            || $quizVersion->quizId !== $campaign->quizId
            || !$quizVersion->isPublished()) {
            throw new RuntimeException('Attempt delivery context is inconsistent.');
        }

        return [
            'attempt_uuid' => $attempt->attemptUuid,
            'status' => $attempt->status,
            'quiz' => $this->sanitizeDefinition($quizVersion->definition()),
        ];
    }

    /**
     * @return array{name: string, questions: list<array{id: string, text: string, order: int, help_text?: string, image_path?: string, options: list<array{id: string, text: string, order: int}>}>}
     */
    private function sanitizeDefinition(QuizDefinition $definition): array
    {
        $questions = [];

        foreach ($definition->questions as $question) {
            $options = [];
            foreach ($question['options'] as $option) {
                $options[] = [
                    'id' => $option['id'],
                    'text' => $option['text'],
                    'order' => $option['order'],
                ];
            }

            $publicQuestion = [
                'id' => $question['id'],
                'text' => $question['text'],
                'order' => $question['order'],
                'options' => $options,
            ];

            if (isset($question['image_path']) && is_string($question['image_path'])) {
                $publicQuestion['image_path'] = $question['image_path'];
            }

            if (isset($question['help_text']) && is_string($question['help_text']) && trim($question['help_text']) !== '') {
                $publicQuestion['help_text'] = $question['help_text'];
            }

            $questions[] = $publicQuestion;
        }

        return [
            'name' => $definition->name,
            'questions' => $questions,
        ];
    }
}
