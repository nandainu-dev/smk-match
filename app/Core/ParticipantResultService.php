<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class ParticipantResultService
{
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly ParticipantRepository $participants,
        private readonly CampaignRepository $campaigns,
        private readonly CampaignBatchRepository $batches,
        private readonly QuizVersionRepository $quizVersions,
        private readonly QuizVersionProgramRepository $versionPrograms,
        private readonly QuizVersionProgramPresentationRepository $presentations,
        private readonly ResultRepository $results,
        private readonly ResultScoreRepository $scores,
        private readonly ResultTiedProgramRepository $tiedPrograms,
    ) {
    }

    /** @return array<string, mixed> */
    public function read(string $attemptUuid, string $visitorUuid): array
    {
        $attempt = $this->attempts->findByAttemptUuid($attemptUuid);
        if ($attempt === null || !hash_equals($attempt->visitorUuid, $visitorUuid)) {
            throw new RuntimeException('Participant result does not exist.');
        }
        if ($attempt->status !== Attempt::STATUS_COMPLETED
            || $attempt->participantId === null
            || $attempt->campaignId === null
            || $attempt->campaignBatchId === null) {
            throw new RuntimeException('Participant result is unavailable.');
        }

        $participant = $this->participants->findById($attempt->participantId);
        $campaign = $this->campaigns->findById($attempt->campaignId);
        $batch = $this->batches->findById($attempt->campaignBatchId);
        $version = $this->quizVersions->findById($attempt->quizVersionId);
        $result = $this->results->findByAttemptId($attempt->id);
        if ($participant === null || $campaign === null || $batch === null || $version === null || $result === null) {
            throw new RuntimeException('Participant result context is unavailable.');
        }
        if ($participant->schoolId !== $campaign->schoolId
            || $batch->campaignId !== $campaign->id
            || $batch->quizVersionId !== $version->id
            || $campaign->quizId === null
            || $campaign->quizId !== $version->quizId) {
            throw new RuntimeException('Participant result context is inconsistent.');
        }

        $programIdsByCode = $this->versionPrograms->findProgramIdsByVersionId($version->id);
        $codeByProgramId = [];
        foreach ($programIdsByCode as $code => $programId) {
            if (isset($codeByProgramId[$programId])) {
                throw new RuntimeException('Participant result program membership is inconsistent.');
            }
            $codeByProgramId[$programId] = $code;
        }

        $profiles = $this->profilesByCode($version->id);
        $profileCodes = array_keys($profiles);
        $programCodes = array_keys($programIdsByCode);
        sort($profileCodes, SORT_STRING);
        sort($programCodes, SORT_STRING);
        if ($profileCodes !== $programCodes) {
            throw new RuntimeException('Participant result presentation snapshots are incomplete.');
        }

        $scores = $this->scores->findScoresByResultId($result->id);
        if (count($scores) !== count($codeByProgramId)) {
            throw new RuntimeException('Participant result scores are incomplete.');
        }

        $ranking = [];
        $percentages = [];
        $seenProgramIds = [];
        foreach ($scores as $score) {
            $code = $codeByProgramId[$score->programId] ?? null;
            if ($code === null || isset($seenProgramIds[$score->programId])) {
                throw new RuntimeException('Participant result scores are inconsistent.');
            }
            $seenProgramIds[$score->programId] = true;
            $ranking[] = ['program' => $code, 'percentage' => $score->normalizedPercentage];
            $percentages[$code] = $score->normalizedPercentage;
        }

        $tiedIds = $this->tiedPrograms->findTiedProgramIdsByResultId($result->id);
        $tiedIdSet = array_fill_keys($tiedIds, true);
        $tiedCodes = array_values(array_map(
            static fn (array $entry): string => $entry['program'],
            array_filter($ranking, static fn (array $entry): bool => isset($tiedIdSet[$programIdsByCode[$entry['program']]])),
        ));

        $dominantCode = $result->dominantProgramId === null ? null : ($codeByProgramId[$result->dominantProgramId] ?? null);
        if ($result->isTie) {
            if ($dominantCode !== null || count($tiedCodes) < 2) {
                throw new RuntimeException('Participant tied result is inconsistent.');
            }
        } elseif ($dominantCode === null || $tiedCodes !== []) {
            throw new RuntimeException('Participant decisive result is inconsistent.');
        }

        return [
            'status' => 'success',
            'participant' => ['display_name' => $participant->fullName],
            'result' => [
                'dominant_program' => $dominantCode,
                'is_tie' => $result->isTie,
                'tied_programs' => $tiedCodes,
                'percentages' => $percentages,
                'ranking' => $ranking,
            ],
            'presentation' => [
                'primary_profile' => $dominantCode === null ? null : $profiles[$dominantCode],
                'profiles' => $profiles,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function profilesByCode(int $quizVersionId): array
    {
        $profiles = [];
        foreach ($this->presentations->findAllByQuizVersionId($quizVersionId) as $presentation) {
            $code = $presentation->programCodeSnapshot;
            if (isset($profiles[$code])) {
                throw new RuntimeException('Participant result has duplicate presentation snapshots.');
            }
            $profiles[$code] = [
                'code' => $code,
                'display_name' => $presentation->programNameSnapshot,
                'personality_title' => $presentation->personalityTitleSnapshot ?? '',
                'mascot_path' => $presentation->mascotPathSnapshot,
                'result_image_path' => $presentation->resultImagePathSnapshot,
                'share_image_path' => $presentation->shareImagePathSnapshot,
                'primary_color' => $this->color($presentation->primaryColorSnapshot, '#334155'),
                'accent_color' => $this->color($presentation->accentColorSnapshot, '#94A3B8'),
                'tagline' => $presentation->taglineSnapshot ?? '',
                'description' => $presentation->descriptionSnapshot ?? '',
                'superpower' => $presentation->superpowerSnapshot ?? '',
                'skills' => $this->stringList($presentation->skillsSnapshot),
                'careers' => $this->stringList($presentation->careersSnapshot),
                'share_headline' => 'Aku mencoba SMK Match!',
            ];
        }

        return $profiles;
    }

    /** @return list<string> */
    private function stringList(?string $json): array
    {
        if ($json === null) {
            return [];
        }
        try {
            $items = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Participant result presentation snapshot is invalid.');
        }
        if (!is_array($items) || !array_is_list($items) || array_filter($items, static fn (mixed $item): bool => !is_string($item)) !== []) {
            throw new RuntimeException('Participant result presentation snapshot is invalid.');
        }

        return $items;
    }

    private function color(?string $color, string $fallback): string
    {
        return $color !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : $fallback;
    }
}
