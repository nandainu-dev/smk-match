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
    ) {}

    public function validate(): void
    {
        if (trim($this->name) === '' || $this->version < 1 || count($this->questions) === 0) throw new \InvalidArgumentException('Invalid quiz.');
        $questionIds = []; $questionOrders = [];
        foreach ($this->questions as $question) {
            if (!is_array($question) || !isset($question['id'], $question['text'], $question['order'], $question['options']) || !is_string($question['id']) || !is_string($question['text']) || !is_array($question['options']) || trim($question['id']) === '' || trim($question['text']) === '' || count($question['options']) < 2 || isset($questionIds[$question['id']]) || isset($questionOrders[$question['order']])) throw new \InvalidArgumentException('Invalid question.');
            $questionIds[$question['id']] = true; $questionOrders[$question['order']] = true; $optionIds = []; $optionOrders = [];
            foreach ($question['options'] as $option) {
                if (!is_array($option) || !isset($option['id'], $option['text'], $option['order'], $option['weights']) || !is_string($option['id']) || !is_string($option['text']) || !is_array($option['weights']) || trim($option['id']) === '' || trim($option['text']) === '' || isset($optionIds[$option['id']]) || isset($optionOrders[$option['order']])) throw new \InvalidArgumentException('Invalid option.');
                $optionIds[$option['id']] = true; $optionOrders[$option['order']] = true; $assignedPrograms = [];
                foreach ($option['weights'] as $assignment) {
                    if (!is_array($assignment) || !isset($assignment['program'], $assignment['weight']) || !is_string($assignment['program']) || trim($assignment['program']) === '' || !isset($this->programs[$assignment['program']]) || !is_numeric($assignment['weight']) || !is_finite((float) $assignment['weight']) || isset($assignedPrograms[$assignment['program']])) throw new \InvalidArgumentException('Invalid weight assignment.');
                    $assignedPrograms[$assignment['program']] = true;
                }
            }
        }
    }
}
