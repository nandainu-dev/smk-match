<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class SubmissionService
{
    public function __construct(
        private readonly Database $database,
        private readonly AttemptRepository $attempts,
        private readonly AttemptResponseRepository $responses,
        private readonly ResultRepository $results,
        private readonly ResultScoreRepository $scores,
        private readonly ResultTiedProgramRepository $tiedPrograms,
        private readonly ParticipantRepository $participants,
        private readonly CampaignRepository $campaigns,
        private readonly CampaignBatchRepository $batches,
        private readonly QuizVersionRepository $quizVersions,
        private readonly QuizVersionProgramRepository $quizVersionPrograms,
        private readonly ScoringEngine $scoringEngine,
    ) {
    }

    /**
     * @param array<int, int> $answers Question database ID => selected option database ID.
     */
    public function submit(string $attemptUuid, array $answers, DateTimeImmutable $submittedAt): SubmissionOutcome
    {
        if (trim($attemptUuid) === '') {
            throw new \InvalidArgumentException('Attempt UUID must not be blank.');
        }

        $connection = $this->connection();
        if ($connection->inTransaction()) {
            throw new \LogicException('Submission service does not support nested transactions.');
        }

        $connection->beginTransaction();

        try {
            $attempt = $this->attempts->findByAttemptUuidForUpdate($attemptUuid);
            if ($attempt === null) {
                throw new \InvalidArgumentException('Attempt does not exist.');
            }

            if ($attempt->status === Attempt::STATUS_COMPLETED) {
                $outcome = $this->completedOutcome($attempt);
                $connection->commit();

                return $outcome;
            }

            if ($attempt->status !== Attempt::STATUS_STARTED) {
                throw new RuntimeException('Attempt has an unsupported submission status.');
            }

            if ($this->results->findByAttemptId($attempt->id) !== null) {
                throw new RuntimeException('Persistence invariant violation: started attempt already has a result.');
            }

            if ($this->responses->findByAttemptId($attempt->id) !== []) {
                throw new RuntimeException('Persistence invariant violation: started attempt already has responses.');
            }

            $context = $this->validateStartedAttemptContext($attempt);
            $g6Answers = $this->transformAnswers($context['definition'], $answers);
            $scoringResult = $this->scoringEngine->score($context['definition'], $g6Answers);
            $programIds = $this->quizVersionPrograms->findProgramIdsByVersionId($attempt->quizVersionId);
            $this->validateProgramMapping($context['definition'], $programIds, $scoringResult);

            foreach ($this->answerDatabaseIds($g6Answers) as $answer) {
                $this->responses->create(
                    $attempt->id,
                    $answer['question_id'],
                    $answer['option_id'],
                    $submittedAt,
                );
            }

            $dominantProgram = $scoringResult['dominant_program'];
            $result = $this->results->create(
                $attempt->id,
                $this->floatResultValue($scoringResult, 'total_raw_score'),
                $dominantProgram === null ? null : $programIds[$dominantProgram],
                $this->boolResultValue($scoringResult, 'is_tie'),
                $submittedAt,
            );

            foreach ($programIds as $programCode => $programId) {
                $this->scores->create(
                    $result->id,
                    $programId,
                    $this->scoreValue($scoringResult, 'raw_scores', $programCode),
                    $this->scoreValue($scoringResult, 'percentages', $programCode),
                    $submittedAt,
                );
            }

            $tiedProgramIds = [];
            foreach ($scoringResult['tied_programs'] as $programCode) {
                $programId = $programIds[$programCode];
                $this->tiedPrograms->create($result->id, $programId);
                $tiedProgramIds[] = $programId;
            }
            sort($tiedProgramIds, SORT_NUMERIC);

            if (!$this->attempts->markCompleted($attempt->id, $submittedAt)) {
                throw new RuntimeException('Attempt completion transition failed.');
            }

            $connection->commit();

            return new SubmissionOutcome(
                $attempt->attemptUuid,
                $result,
                $this->scores->findScoresByResultId($result->id),
                $tiedProgramIds,
                false,
            );
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }
    }

    private function completedOutcome(Attempt $attempt): SubmissionOutcome
    {
        $result = $this->results->findByAttemptId($attempt->id);
        if ($result === null) {
            throw new RuntimeException('Persistence invariant violation: completed attempt is missing a result.');
        }

        $programIds = $this->quizVersionPrograms->findProgramIdsByVersionId($attempt->quizVersionId);
        $scores = $this->scores->findScoresByResultId($result->id);
        $tiedProgramIds = $this->tiedPrograms->findTiedProgramIdsByResultId($result->id);
        $expectedProgramIds = array_values($programIds);
        sort($expectedProgramIds, SORT_NUMERIC);

        if (count($scores) !== count($expectedProgramIds)) {
            throw new RuntimeException('Persistence invariant violation: completed result has incomplete scores.');
        }

        $scoreProgramIds = array_map(static fn(ResultScore $score): int => $score->programId, $scores);
        if ($scoreProgramIds !== $expectedProgramIds) {
            throw new RuntimeException('Persistence invariant violation: completed result scores do not match version programs.');
        }

        if ($result->isTie) {
            if ($result->dominantProgramId !== null || count($tiedProgramIds) < 2) {
                throw new RuntimeException('Persistence invariant violation: completed tied result is inconsistent.');
            }
        } elseif ($tiedProgramIds !== []) {
            throw new RuntimeException('Persistence invariant violation: completed non-tie result has tied programs.');
        }

        return new SubmissionOutcome($attempt->attemptUuid, $result, $scores, $tiedProgramIds, true);
    }

    /** @return array{definition: QuizDefinition} */
    private function validateStartedAttemptContext(Attempt $attempt): array
    {
        if ($attempt->participantId === null || $attempt->campaignId === null || $attempt->campaignBatchId === null) {
            throw new RuntimeException('Legacy attempts cannot be submitted through the final submission service.');
        }

        $participant = $this->participants->findById($attempt->participantId);
        $campaign = $this->campaigns->findById($attempt->campaignId);
        $batch = $this->batches->findById($attempt->campaignBatchId);
        $quizVersion = $this->quizVersions->findById($attempt->quizVersionId);

        if ($participant === null || $campaign === null || $batch === null || $quizVersion === null) {
            throw new RuntimeException('Attempt submission context does not exist.');
        }

        if ($attempt->campaignId !== $batch->campaignId) {
            throw new RuntimeException('Attempt campaign does not match its batch.');
        }

        if ($attempt->quizVersionId !== $batch->quizVersionId) {
            throw new RuntimeException('Attempt quiz version does not match its batch.');
        }

        if ($campaign->quizId === null || $quizVersion->quizId !== $campaign->quizId) {
            throw new RuntimeException('Quiz version does not belong to the attempt campaign quiz.');
        }

        if ($participant->schoolId !== $campaign->schoolId) {
            throw new RuntimeException('Participant school does not match attempt campaign school.');
        }

        if (!$quizVersion->isPublished()) {
            throw new RuntimeException('Attempt quiz version is not published.');
        }

        return ['definition' => $quizVersion->definition()];
    }

    /** @param array<int, int> $answers @return list<array{question_id: string, option_id: string}> */
    private function transformAnswers(QuizDefinition $definition, array $answers): array
    {
        $questionOptions = [];
        foreach ($definition->questions as $question) {
            $questionId = $this->databaseId($question['id'] ?? null, 'question-');
            $options = [];
            foreach ($question['options'] ?? [] as $option) {
                $optionId = $this->databaseId($option['id'] ?? null, 'option-');
                $options[$optionId] = $option['id'];
            }
            $questionOptions[$questionId] = [
                'g6_question_id' => $question['id'],
                'options' => $options,
            ];
        }

        if (count($answers) !== count($questionOptions)) {
            throw new \InvalidArgumentException('Every quiz version question requires exactly one answer.');
        }

        $g6Answers = [];
        foreach ($answers as $questionId => $optionId) {
            if (!is_int($questionId) || !is_int($optionId) || $questionId < 1 || $optionId < 1) {
                throw new \InvalidArgumentException('Submission answers must map positive question IDs to positive option IDs.');
            }

            if (!isset($questionOptions[$questionId])) {
                throw new \InvalidArgumentException('Submission contains an unknown or wrong-version question.');
            }

            $question = $questionOptions[$questionId];
            if (!isset($question['options'][$optionId])) {
                throw new \InvalidArgumentException('Submission option does not belong to its question.');
            }

            $g6Answers[] = [
                'question_id' => $question['g6_question_id'],
                'option_id' => $question['options'][$optionId],
            ];
        }

        return $g6Answers;
    }

    /** @param list<array{question_id: string, option_id: string}> $g6Answers @return list<array{question_id: int, option_id: int}> */
    private function answerDatabaseIds(array $g6Answers): array
    {
        $answers = [];
        foreach ($g6Answers as $answer) {
            $answers[] = [
                'question_id' => $this->databaseId($answer['question_id'], 'question-'),
                'option_id' => $this->databaseId($answer['option_id'], 'option-'),
            ];
        }

        return $answers;
    }

    /** @param array<string, int> $programIds @param array<string, mixed> $scoringResult */
    private function validateProgramMapping(QuizDefinition $definition, array $programIds, array $scoringResult): void
    {
        if ($programIds === [] || array_keys($definition->programs) !== array_keys($programIds)) {
            throw new RuntimeException('Quiz version program membership does not match its definition.');
        }

        $rawScores = $scoringResult['raw_scores'] ?? null;
        $percentages = $scoringResult['percentages'] ?? null;
        if (!is_array($rawScores) || !is_array($percentages)
            || array_keys($rawScores) !== array_keys($programIds)
            || array_keys($percentages) !== array_keys($programIds)) {
            throw new RuntimeException('Scoring output programs do not match quiz version membership.');
        }

        foreach (array_keys($programIds) as $programCode) {
            $this->scoreValue($scoringResult, 'raw_scores', $programCode);
            $this->scoreValue($scoringResult, 'percentages', $programCode);
        }

        $isTie = $this->boolResultValue($scoringResult, 'is_tie');
        $dominantProgram = $scoringResult['dominant_program'] ?? null;
        $tiedPrograms = $scoringResult['tied_programs'] ?? null;
        if (!is_array($tiedPrograms)) {
            throw new RuntimeException('Scoring output tied programs are invalid.');
        }

        if ($isTie) {
            if ($dominantProgram !== null || count($tiedPrograms) < 2) {
                throw new RuntimeException('Scoring output tie state is inconsistent.');
            }
        } elseif (!is_string($dominantProgram) || !isset($programIds[$dominantProgram]) || $tiedPrograms !== []) {
            throw new RuntimeException('Scoring output non-tie state is inconsistent.');
        }

        $seenTiedPrograms = [];
        foreach ($tiedPrograms as $programCode) {
            if (!is_string($programCode) || !isset($programIds[$programCode]) || isset($seenTiedPrograms[$programCode])) {
                throw new RuntimeException('Scoring output tied program mapping is invalid.');
            }
            $seenTiedPrograms[$programCode] = true;
        }

        $this->floatResultValue($scoringResult, 'total_raw_score');
    }

    /** @param array<string, mixed> $scoringResult */
    private function floatResultValue(array $scoringResult, string $key): float
    {
        if (!array_key_exists($key, $scoringResult) || !is_float($scoringResult[$key]) || !is_finite($scoringResult[$key])) {
            throw new RuntimeException('Scoring output ' . $key . ' is invalid.');
        }

        return $scoringResult[$key];
    }

    /** @param array<string, mixed> $scoringResult */
    private function boolResultValue(array $scoringResult, string $key): bool
    {
        if (!array_key_exists($key, $scoringResult) || !is_bool($scoringResult[$key])) {
            throw new RuntimeException('Scoring output ' . $key . ' is invalid.');
        }

        return $scoringResult[$key];
    }

    /** @param array<string, mixed> $scoringResult */
    private function scoreValue(array $scoringResult, string $key, string $programCode): float
    {
        if (!isset($scoringResult[$key]) || !is_array($scoringResult[$key])
            || !array_key_exists($programCode, $scoringResult[$key])
            || !is_float($scoringResult[$key][$programCode])
            || !is_finite($scoringResult[$key][$programCode])) {
            throw new RuntimeException('Scoring output ' . $key . ' is invalid.');
        }

        return $scoringResult[$key][$programCode];
    }

    private function databaseId(mixed $identifier, string $prefix): int
    {
        if (!is_string($identifier) || !str_starts_with($identifier, $prefix)) {
            throw new RuntimeException('Quiz version definition identifier is invalid.');
        }

        $suffix = substr($identifier, strlen($prefix));
        if ($suffix === '' || !ctype_digit($suffix) || (int) $suffix < 1) {
            throw new RuntimeException('Quiz version definition identifier is invalid.');
        }

        return (int) $suffix;
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }
}
