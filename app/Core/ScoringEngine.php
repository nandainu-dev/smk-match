<?php
declare(strict_types=1);

namespace App\Core;

final class ScoringEngine
{
    /** @param list<array<string, mixed>> $answers @return array<string, mixed> */
    public function score(QuizDefinition $quiz, array $answers): array
    {
        $quiz->validate();
        if ($answers === []) {
            throw new \InvalidArgumentException('Answers are required.');
        }

        $questions = $this->questionLookup($quiz);
        $selected = [];
        foreach ($answers as $answer) {
            if (!is_array($answer) || !isset($answer['question_id'], $answer['option_id']) || !is_string($answer['question_id']) || !is_string($answer['option_id']) || trim($answer['question_id']) === '' || trim($answer['option_id']) === '') {
                throw new \InvalidArgumentException('Invalid answer row.');
            }
            $questionId = $answer['question_id'];
            if (!isset($questions[$questionId]) || isset($selected[$questionId])) {
                throw new \InvalidArgumentException('Unknown or duplicate question answer.');
            }
            $option = $questions[$questionId][$answer['option_id']] ?? null;
            if ($option === null) {
                throw new \InvalidArgumentException('Unknown option for question.');
            }
            $selected[$questionId] = $option;
        }
        if (count($selected) !== count($questions)) {
            throw new \InvalidArgumentException('Every question requires one answer.');
        }

        $rawScores = array_fill_keys(array_keys($quiz->programs), 0.0);
        $selectedQuestionIds = array_keys($selected);
        sort($selectedQuestionIds, SORT_STRING);
        foreach ($selectedQuestionIds as $questionId) {
            $option = $selected[$questionId];
            foreach ($option['weights'] as $assignment) {
                $rawScores[$assignment['program']] += (float) $assignment['weight'];
            }
        }
        $total = array_sum($rawScores);
        if ($total <= 0.0) {
            throw new \DomainException('Scoring total is not positive.');
        }

        $percentages = [];
        foreach ($rawScores as $program => $rawScore) {
            $percentages[$program] = round(($rawScore / $total) * 100, 2, PHP_ROUND_HALF_UP);
        }
        $highest = max($rawScores);
        $tiedPrograms = array_keys(array_filter($rawScores, fn(float $score): bool => $this->scoresEqual($score, $highest)));
        sort($tiedPrograms, SORT_STRING);
        $ranking = [];
        foreach ($rawScores as $program => $rawScore) {
            $ranking[] = ['program' => $program, 'raw_score' => $rawScore, 'percentage' => $percentages[$program]];
        }
        usort($ranking, function (array $left, array $right): int {
            if ($this->scoresEqual($left['raw_score'], $right['raw_score'])) return $left['program'] <=> $right['program'];
            return $right['raw_score'] <=> $left['raw_score'];
        });

        return ['total_raw_score' => $total, 'raw_scores' => $rawScores, 'percentages' => $percentages, 'ranking' => $ranking, 'dominant_program' => count($tiedPrograms) === 1 ? $tiedPrograms[0] : null, 'is_tie' => count($tiedPrograms) > 1, 'tied_programs' => count($tiedPrograms) > 1 ? $tiedPrograms : []];
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function questionLookup(QuizDefinition $quiz): array
    {
        $lookup = [];
        foreach ($quiz->questions as $question) {
            $lookup[$question['id']] = [];
            foreach ($question['options'] as $option) {
                $lookup[$question['id']][$option['id']] = $option;
            }
        }
        return $lookup;
    }

    private function scoresEqual(float $left, float $right): bool
    {
        return abs($left - $right) <= 1e-12 * max(1.0, abs($left), abs($right));
    }
}
