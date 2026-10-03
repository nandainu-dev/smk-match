<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\QuizDefinition;
use App\Core\QuizVersion;
use App\Core\ScoringEngine;
use App\Core\ThreeProgramQuizConfiguration;

function threeProgramAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function threeProgramExpectFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message);
}

$configuration = new ThreeProgramQuizConfiguration();
$activePrograms = [
    ['id' => 101, 'code' => 'DKV', 'name' => 'Desain Komunikasi Visual'],
    ['id' => 102, 'code' => 'MPLB', 'name' => 'Manajemen Perkantoran'],
    ['id' => 103, 'code' => 'PM', 'name' => 'Pemasaran'],
    ['id' => 104, 'code' => 'RPL', 'name' => 'Rekayasa Perangkat Lunak'],
];

$programs = $configuration->selectedProgramCodes($activePrograms, [101, 103, 102]);
threeProgramAssert(array_keys($programs) === ['DKV', 'PM', 'MPLB'], 'Program configuration did not resolve the selected stable IDs.');
threeProgramExpectFailure(
    fn (): array => $configuration->selectedProgramCodes($activePrograms, [101, 102, 102]),
    'Duplicate program selections were accepted.',
);
threeProgramExpectFailure(
    fn (): array => $configuration->selectedProgramCodes($activePrograms, [101, 102, 104, 103]),
    'More than three program selections were accepted.',
);
threeProgramExpectFailure(
    fn (): array => $configuration->selectedProgramCodes($activePrograms, [101, 102, 999]),
    'A foreign or inactive program selection was accepted.',
);

$definition = new QuizDefinition('Three-program draft', 1, $programs, [[
    'id' => 'question-1',
    'text' => 'Choose one answer.',
    'order' => 10,
    'options' => [
        ['id' => 'option-a', 'text' => 'A', 'order' => 10, 'weights' => [['program' => 'DKV', 'weight' => 1.0]]],
        ['id' => 'option-b', 'text' => 'B', 'order' => 20, 'weights' => [['program' => 'PM', 'weight' => 1.0]]],
        ['id' => 'option-c', 'text' => 'C', 'order' => 30, 'weights' => [['program' => 'DKV', 'weight' => 2.0]]],
        ['id' => 'option-d', 'text' => 'D', 'order' => 40, 'weights' => [['program' => 'MPLB', 'weight' => 1.0]]],
    ],
]]);
$definition->validate();
threeProgramAssert(
    $definition->questions[0]['options'][0]['weights'][0]['program'] === 'DKV'
        && $definition->questions[0]['options'][2]['weights'][0]['program'] === 'DKV',
    'Independent duplicate option mapping was not preserved.',
);
$scored = (new ScoringEngine())->score($definition, [['question_id' => 'question-1', 'option_id' => 'option-c']]);
threeProgramAssert(
    $scored['dominant_program'] === 'DKV'
        && $scored['raw_scores']['DKV'] === 2.0
        && $scored['raw_scores']['PM'] === 0.0
        && $scored['raw_scores']['MPLB'] === 0.0,
    'Arbitrary duplicate option mapping changed scoring behavior.',
);

$configuration->assertVersion(new QuizVersion(1, 1, 1, QuizVersion::STATUS_DRAFT, 'Three-program draft', $definition));
$fourProgramDefinition = new QuizDefinition('Four-program draft', 1, array_fill_keys(['DKV', 'MPLB', 'PM', 'RPL'], true), $definition->questions);
threeProgramExpectFailure(
    fn (): null => $configuration->assertVersion(new QuizVersion(1, 2, 1, QuizVersion::STATUS_DRAFT, 'Four-program draft', $fourProgramDefinition)),
    'The product configuration accepted a four-program version.',
);

echo "Three-program configuration tests passed.\n";
