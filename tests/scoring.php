<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\QuizDefinition;
use App\Core\ScoringEngine;

/** @param array<string, mixed> $condition */
function assertScoring(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectException(string $exceptionClass, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $exceptionClass) {
            return;
        }

        throw new RuntimeException($message . ': unexpected ' . $throwable::class);
    }

    throw new RuntimeException($message . ': exception was not thrown');
}

/** @param list<array{program: string, weight: int|float}> $weights */
function scoringOption(string $id, int $order, array $weights): array
{
    return [
        'id' => $id,
        'text' => 'Option ' . $id,
        'order' => $order,
        'weights' => $weights,
    ];
}

/** @param list<array<string, mixed>> $options */
function scoringQuestion(string $id, int $order, array $options): array
{
    return [
        'id' => $id,
        'text' => 'Question ' . $id,
        'order' => $order,
        'options' => $options,
    ];
}

/** @param array<string, bool> $programs @param list<array<string, mixed>> $questions */
function scoringQuiz(array $programs, array $questions): QuizDefinition
{
    return new QuizDefinition('Scoring test quiz', 1, $programs, $questions);
}

/** @param list<array{question_id: string, option_id: string}> $answers */
function scoreQuiz(QuizDefinition $quiz, array $answers): array
{
    return (new ScoringEngine())->score($quiz, $answers);
}

$programs = [
    'ALPHA' => true,
    'BETA' => true,
    'GAMMA' => true,
    'DELTA' => true,
];

$basicQuestions = [
    scoringQuestion('q1', 1, [
        scoringOption('a', 1, [
            ['program' => 'ALPHA', 'weight' => 2],
            ['program' => 'BETA', 'weight' => 1],
        ]),
        scoringOption('x', 2, []),
    ]),
    scoringQuestion('q2', 2, [
        scoringOption('b', 1, [
            ['program' => 'ALPHA', 'weight' => 1],
        ]),
        scoringOption('x2', 2, []),
    ]),
];

$result = scoreQuiz(scoringQuiz($programs, $basicQuestions), [
    ['question_id' => 'q1', 'option_id' => 'a'],
    ['question_id' => 'q2', 'option_id' => 'b'],
]);

assertScoring(
    $result['total_raw_score'] === 4.0
        && $result['dominant_program'] === 'ALPHA'
        && $result['raw_scores']['DELTA'] === 0.0,
    'basic',
);
assertScoring($result['percentages']['BETA'] === 25.0, 'percent');

$halfUpResult = scoreQuiz(scoringQuiz([
    'ALPHA' => true,
    'BETA' => true,
], [
    scoringQuestion('half-up', 1, [
        scoringOption('selected', 1, [
            ['program' => 'ALPHA', 'weight' => 12345],
            ['program' => 'BETA', 'weight' => 87655],
        ]),
        scoringOption('other', 2, []),
    ]),
]), [
    ['question_id' => 'half-up', 'option_id' => 'selected'],
]);

assertScoring(
    $halfUpResult['percentages']['ALPHA'] === 12.35,
    'Percentages must round halfway values to two decimal places using half-up rounding.',
);

$decimalQuestions = [
    scoringQuestion('q1', 1, [
        scoringOption('a', 1, [
            ['program' => 'ALPHA', 'weight' => 0.1],
            ['program' => 'BETA', 'weight' => 0.3],
        ]),
        scoringOption('x', 2, []),
    ]),
    scoringQuestion('q2', 2, [
        scoringOption('b', 1, [
            ['program' => 'ALPHA', 'weight' => 0.2],
        ]),
        scoringOption('x2', 2, []),
    ]),
];

$decimalTie = scoreQuiz(scoringQuiz([
    'ALPHA' => true,
    'BETA' => true,
], $decimalQuestions), [
    ['question_id' => 'q1', 'option_id' => 'a'],
    ['question_id' => 'q2', 'option_id' => 'b'],
]);

assertScoring($decimalTie['is_tie'] === true, 'decimal tie');
assertScoring(
    array_column($decimalTie['ranking'], 'program') === ['ALPHA', 'BETA'],
    'Decimal-equivalent ranking ties must use program-code order.',
);

$rankedResult = scoreQuiz(scoringQuiz($programs, [
    scoringQuestion('ranked', 1, [
        scoringOption('selected', 1, [
            ['program' => 'ALPHA', 'weight' => 30],
            ['program' => 'BETA', 'weight' => 20],
            ['program' => 'GAMMA', 'weight' => 10],
        ]),
        scoringOption('other', 2, []),
    ]),
]), [
    ['question_id' => 'ranked', 'option_id' => 'selected'],
]);

assertScoring(
    array_column($rankedResult['ranking'], 'program') === ['ALPHA', 'BETA', 'GAMMA', 'DELTA'],
    'Unique scores must rank by raw score descending.',
);
assertScoring(
    array_column($rankedResult['ranking'], 'raw_score') === [30.0, 20.0, 10.0, 0.0],
    'Ranking must retain raw scores.',
);

$integerTie = scoreQuiz(scoringQuiz($programs, [
    scoringQuestion('tie', 1, [
        scoringOption('selected', 1, [
            ['program' => 'ALPHA', 'weight' => 20],
            ['program' => 'BETA', 'weight' => 20],
            ['program' => 'GAMMA', 'weight' => 1],
        ]),
        scoringOption('other', 2, []),
    ]),
]), [
    ['question_id' => 'tie', 'option_id' => 'selected'],
]);

assertScoring($integerTie['dominant_program'] === null, 'Exact top ties must not select a dominant program.');
assertScoring($integerTie['is_tie'] === true, 'Exact top ties must be marked as ties.');
assertScoring(
    $integerTie['tied_programs'] === ['ALPHA', 'BETA'],
    'Only highest-scoring programs must appear in sorted tied_programs.',
);
assertScoring(
    array_column($integerTie['ranking'], 'program') === ['ALPHA', 'BETA', 'GAMMA', 'DELTA'],
    'Equal integer scores must rank by program code ascending.',
);

$secondPlaceTie = scoreQuiz(scoringQuiz($programs, [
    scoringQuestion('second-place-tie', 1, [
        scoringOption('selected', 1, [
            ['program' => 'ALPHA', 'weight' => 30],
            ['program' => 'BETA', 'weight' => 20],
            ['program' => 'GAMMA', 'weight' => 20],
        ]),
        scoringOption('other', 2, []),
    ]),
]), [
    ['question_id' => 'second-place-tie', 'option_id' => 'selected'],
]);

assertScoring(
    $secondPlaceTie['dominant_program'] === 'ALPHA'
        && $secondPlaceTie['is_tie'] === false
        && $secondPlaceTie['tied_programs'] === [],
    'A second-place tie must not change a unique dominant program.',
);
assertScoring(
    array_column($secondPlaceTie['ranking'], 'program') === ['ALPHA', 'BETA', 'GAMMA', 'DELTA'],
    'Second-place ties must keep the ranking sorted by program code after raw score.',
);

$zeroTotalQuiz = scoringQuiz($programs, [
    scoringQuestion('zero-total', 1, [
        scoringOption('selected', 1, [
            ['program' => 'ALPHA', 'weight' => 0],
            ['program' => 'BETA', 'weight' => 0],
        ]),
        scoringOption('other', 2, []),
    ]),
]);

expectException(DomainException::class, function () use ($zeroTotalQuiz): void {
    scoreQuiz($zeroTotalQuiz, [
        ['question_id' => 'zero-total', 'option_id' => 'selected'],
    ]);
}, 'A zero-total attempt must not be scoreable');

$unansweredQuiz = scoringQuiz($programs, [
    scoringQuestion('answered', 1, [
        scoringOption('selected', 1, [['program' => 'ALPHA', 'weight' => 1]]),
        scoringOption('other', 2, []),
    ]),
    scoringQuestion('unanswered', 2, [
        scoringOption('selected', 1, [['program' => 'BETA', 'weight' => 1]]),
        scoringOption('other', 2, []),
    ]),
]);

expectException(InvalidArgumentException::class, function () use ($unansweredQuiz): void {
    scoreQuiz($unansweredQuiz, [
        ['question_id' => 'answered', 'option_id' => 'selected'],
    ]);
}, 'Every quiz question must have one answer');

assertScoring(
    array_keys($rankedResult['raw_scores']) === ['ALPHA', 'BETA', 'GAMMA', 'DELTA']
        && array_keys($rankedResult['percentages']) === ['ALPHA', 'BETA', 'GAMMA', 'DELTA']
        && array_column($rankedResult['ranking'], 'program') === ['ALPHA', 'BETA', 'GAMMA', 'DELTA'],
    'Every configured program must occur exactly once in score, percentage, and ranking outputs.',
);

assertScoring(
    array_keys($rankedResult) === [
        'total_raw_score',
        'raw_scores',
        'percentages',
        'ranking',
        'dominant_program',
        'is_tie',
        'tied_programs',
    ]
        && is_float($rankedResult['total_raw_score'])
        && is_array($rankedResult['raw_scores'])
        && is_array($rankedResult['percentages'])
        && is_array($rankedResult['ranking'])
        && is_string($rankedResult['dominant_program'])
        && is_bool($rankedResult['is_tie'])
        && is_array($rankedResult['tied_programs'])
        && $rankedResult['ranking'][0] === [
            'program' => 'ALPHA',
            'raw_score' => 30.0,
            'percentage' => 50.0,
        ],
    'The result shape and value types must match the scoring contract.',
);

$firstPresentation = scoringQuiz($programs, [
    scoringQuestion('position', 1, [
        scoringOption('selected', 1, [
            ['program' => 'ALPHA', 'weight' => 3],
            ['program' => 'BETA', 'weight' => 1],
        ]),
        scoringOption('other', 2, [['program' => 'GAMMA', 'weight' => 9]]),
    ]),
]);
$secondPresentation = scoringQuiz($programs, [
    scoringQuestion('position', 1, [
        scoringOption('other', 1, [['program' => 'GAMMA', 'weight' => 9]]),
        scoringOption('selected', 2, [
            ['program' => 'ALPHA', 'weight' => 3],
            ['program' => 'BETA', 'weight' => 1],
        ]),
    ]),
]);

$positionAnswers = [['question_id' => 'position', 'option_id' => 'selected']];
assertScoring(
    scoreQuiz($firstPresentation, $positionAnswers) === scoreQuiz($secondPresentation, $positionAnswers),
    'Selected option identity and weights, not presentation position, must determine a result.',
);

$invalidInputQuiz = scoringQuiz($programs, [
    scoringQuestion('q1', 1, [
        scoringOption('q1-a', 1, [['program' => 'ALPHA', 'weight' => 2]]),
        scoringOption('q1-b', 2, [['program' => 'BETA', 'weight' => 1]]),
    ]),
    scoringQuestion('q2', 2, [
        scoringOption('q2-a', 1, [['program' => 'BETA', 'weight' => 2]]),
        scoringOption('q2-b', 2, [['program' => 'GAMMA', 'weight' => 1]]),
    ]),
    scoringQuestion('q3', 3, [
        scoringOption('q3-a', 1, [['program' => 'GAMMA', 'weight' => 2]]),
        scoringOption('q3-b', 2, [['program' => 'DELTA', 'weight' => 1]]),
    ]),
]);

$validInvalidInputAnswers = [
    ['question_id' => 'q1', 'option_id' => 'q1-a'],
    ['question_id' => 'q2', 'option_id' => 'q2-a'],
    ['question_id' => 'q3', 'option_id' => 'q3-a'],
];

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz): void {
    scoreQuiz($invalidInputQuiz, []);
}, 'An empty answer set must be rejected');

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz): void {
    scoreQuiz($invalidInputQuiz, ['invalid-row']);
}, 'A non-array answer row must be rejected');

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz): void {
    scoreQuiz($invalidInputQuiz, [['option_id' => 'q1-a']]);
}, 'An answer without question_id must be rejected');

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz): void {
    scoreQuiz($invalidInputQuiz, [['question_id' => 'q1']]);
}, 'An answer without option_id must be rejected');

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz): void {
    scoreQuiz($invalidInputQuiz, [['question_id' => 123, 'option_id' => 'q1-a']]);
}, 'A non-string question_id must be rejected');

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz): void {
    scoreQuiz($invalidInputQuiz, [['question_id' => 'q1', 'option_id' => 123]]);
}, 'A non-string option_id must be rejected');

foreach (['', '   '] as $questionId) {
    expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz, $questionId): void {
        scoreQuiz($invalidInputQuiz, [['question_id' => $questionId, 'option_id' => 'q1-a']]);
    }, 'A blank question_id must be rejected');
}

foreach (['', '   '] as $optionId) {
    expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz, $optionId): void {
        scoreQuiz($invalidInputQuiz, [['question_id' => 'q1', 'option_id' => $optionId]]);
    }, 'A blank option_id must be rejected');
}

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz): void {
    scoreQuiz($invalidInputQuiz, [['question_id' => 'unknown-question', 'option_id' => 'q1-a']]);
}, 'An unknown question must be rejected');

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz): void {
    scoreQuiz($invalidInputQuiz, [['question_id' => 'q1', 'option_id' => 'unknown-option']]);
}, 'An unknown option must be rejected');

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz): void {
    scoreQuiz($invalidInputQuiz, [['question_id' => 'q1', 'option_id' => 'q2-a']]);
}, 'An option from another question must be rejected');

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz): void {
    scoreQuiz($invalidInputQuiz, [
        ['question_id' => 'q1', 'option_id' => 'q1-a'],
        ['question_id' => 'q1', 'option_id' => 'q1-b'],
    ]);
}, 'A duplicate answer for one question must be rejected');

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz): void {
    scoreQuiz($invalidInputQuiz, [
        ['question_id' => 'q1', 'option_id' => 'q1-a'],
        ['question_id' => 'q2', 'option_id' => 'q2-a'],
    ]);
}, 'An unanswered question must be rejected');

expectException(InvalidArgumentException::class, function () use ($invalidInputQuiz, $validInvalidInputAnswers): void {
    scoreQuiz($invalidInputQuiz, [
        ...$validInvalidInputAnswers,
        ['question_id' => 'unknown-question', 'option_id' => 'q1-a'],
    ]);
}, 'An extra unknown answer after a valid complete set must be rejected');

$orderedAnswersResult = scoreQuiz($invalidInputQuiz, $validInvalidInputAnswers);
$reorderedAnswersResult = scoreQuiz($invalidInputQuiz, [
    ['question_id' => 'q3', 'option_id' => 'q3-a'],
    ['question_id' => 'q1', 'option_id' => 'q1-a'],
    ['question_id' => 'q2', 'option_id' => 'q2-a'],
]);

assertScoring(
    $orderedAnswersResult === $reorderedAnswersResult,
    'Answer-row presentation order must not change the scoring result.',
);

$decimalOrderQuiz = scoringQuiz([
    'ALPHA' => true,
    'BETA' => true,
], [
    scoringQuestion('q1', 1, [
        scoringOption('selected', 1, [['program' => 'ALPHA', 'weight' => 0.1]]),
        scoringOption('other', 2, []),
    ]),
    scoringQuestion('q2', 2, [
        scoringOption('selected', 1, [['program' => 'ALPHA', 'weight' => 0.2]]),
        scoringOption('other', 2, []),
    ]),
    scoringQuestion('q3', 3, [
        scoringOption('selected', 1, [['program' => 'ALPHA', 'weight' => 0.3]]),
        scoringOption('other', 2, []),
    ]),
]);

$forwardDecimalResult = scoreQuiz($decimalOrderQuiz, [
    ['question_id' => 'q1', 'option_id' => 'selected'],
    ['question_id' => 'q2', 'option_id' => 'selected'],
    ['question_id' => 'q3', 'option_id' => 'selected'],
]);
$reverseDecimalResult = scoreQuiz($decimalOrderQuiz, [
    ['question_id' => 'q3', 'option_id' => 'selected'],
    ['question_id' => 'q2', 'option_id' => 'selected'],
    ['question_id' => 'q1', 'option_id' => 'selected'],
]);

assertScoring(
    $forwardDecimalResult === $reverseDecimalResult,
    'Canonical question processing must make decimal raw output independent of answer-row order.',
);

echo "Scoring tests passed.\n";
