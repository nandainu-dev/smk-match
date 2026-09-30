<?php
declare(strict_types=1);

namespace App\Core;

final class QuizDefinition
{
    /** @param array<string, bool> $programs @param list<array<string, mixed>> $questions */
    public function __construct(
        public readonly string $name,
        public readonly int $version,
        public readonly array $programs,
        public readonly array $questions,
    ) {
    }

    public function validate(): void
    {
        if (trim($this->name) === '' || $this->version < 1 || count($this->questions) === 0) {
            throw new \InvalidArgumentException('Invalid quiz.');
        }

        $questionIds = [];
        $questionOrders = [];

        foreach ($this->questions as $question) {
            $this->validateQuestion($question, $questionIds, $questionOrders);

            $questionIds[$question['id']] = true;
            $questionOrders[$question['order']] = true;
            $optionIds = [];
            $optionOrders = [];

            foreach ($question['options'] as $option) {
                $this->validateOption($option, $optionIds, $optionOrders);

                $optionIds[$option['id']] = true;
                $optionOrders[$option['order']] = true;
                $assignedPrograms = [];

                foreach ($option['weights'] as $assignment) {
                    if (!is_array($assignment)
                        || !array_key_exists('program', $assignment)
                        || !array_key_exists('weight', $assignment)
                        || !is_string($assignment['program'])
                        || trim($assignment['program']) === ''
                        || !isset($this->programs[$assignment['program']])
                        || !is_numeric($assignment['weight'])
                        || !is_finite((float) $assignment['weight'])
                        || isset($assignedPrograms[$assignment['program']])) {
                        throw new \InvalidArgumentException('Invalid weight assignment.');
                    }

                    $assignedPrograms[$assignment['program']] = true;
                }
            }
        }
    }

    /** @param array<string, mixed> $question @param array<string, bool> $questionIds @param array<int|string, bool> $questionOrders */
    private function validateQuestion(array $question, array $questionIds, array $questionOrders): void
    {
        if (!array_key_exists('id', $question)
            || !array_key_exists('text', $question)
            || !array_key_exists('order', $question)
            || !array_key_exists('options', $question)
            || !is_string($question['id'])
            || !is_string($question['text'])
            || !is_int($question['order'])
            || !is_array($question['options'])
            || trim($question['id']) === ''
            || trim($question['text']) === ''
            || count($question['options']) < 2
            || isset($questionIds[$question['id']])
            || isset($questionOrders[$question['order']])) {
            throw new \InvalidArgumentException('Invalid question.');
        }

        if (array_key_exists('image_path', $question)
            && $question['image_path'] !== null
            && (!is_string($question['image_path']) || !$this->isSafeQuestionImagePath($question['image_path']))) {
            throw new \InvalidArgumentException('Invalid question image path.');
        }

        if (array_key_exists('help_text', $question)
            && $question['help_text'] !== null
            && !is_string($question['help_text'])) {
            throw new \InvalidArgumentException('Invalid question help text.');
        }
    }

    /** @param array<string, mixed> $option @param array<string, bool> $optionIds @param array<int|string, bool> $optionOrders */
    private function validateOption(array $option, array $optionIds, array $optionOrders): void
    {
        if (!array_key_exists('id', $option)
            || !array_key_exists('text', $option)
            || !array_key_exists('order', $option)
            || !array_key_exists('weights', $option)
            || !is_string($option['id'])
            || !is_string($option['text'])
            || !is_int($option['order'])
            || !is_array($option['weights'])
            || trim($option['id']) === ''
            || trim($option['text']) === ''
            || isset($optionIds[$option['id']])
            || isset($optionOrders[$option['order']])) {
            throw new \InvalidArgumentException('Invalid option.');
        }
    }

    private function isSafeQuestionImagePath(string $path): bool
    {
        return preg_match(
            '#\A/uploads/questions/[a-f0-9]{64}\.(?:jpg|jpeg|png|webp)\z#D',
            $path,
        ) === 1;
    }
}
