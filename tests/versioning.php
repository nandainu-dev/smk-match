<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\QuizDefinition;
use App\Core\QuizVersion;
use App\Core\ScoringEngine;

function assertVersioning(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($throwable instanceof InvalidArgumentException) {
            return;
        }

        throw new RuntimeException($message . ': unexpected ' . $throwable::class);
    }

    throw new RuntimeException($message . ': exception was not thrown');
}

function validDefinition(string $name = 'Historical Quiz', int $version = 1): QuizDefinition
{
    return new QuizDefinition($name, $version, [
        'ALPHA' => true,
        'BETA' => true,
        'GAMMA' => true,
        'DELTA' => true,
    ], [
        [
            'id' => 'q1',
            'text' => 'Question one',
            'order' => 1,
            'options' => [
                [
                    'id' => 'q1a',
                    'text' => 'Option one',
                    'order' => 1,
                    'weights' => [
                        ['program' => 'ALPHA', 'weight' => 2],
                        ['program' => 'BETA', 'weight' => 1],
                    ],
                ],
                [
                    'id' => 'q1b',
                    'text' => 'Option two',
                    'order' => 2,
                    'weights' => [
                        ['program' => 'GAMMA', 'weight' => 2],
                    ],
                ],
            ],
        ],
        [
            'id' => 'q2',
            'text' => 'Question two',
            'order' => 2,
            'options' => [
                [
                    'id' => 'q2a',
                    'text' => 'Option one',
                    'order' => 1,
                    'weights' => [
                        ['program' => 'DELTA', 'weight' => 2],
                    ],
                ],
                [
                    'id' => 'q2b',
                    'text' => 'Option two',
                    'order' => 2,
                    'weights' => [
                        ['program' => 'BETA', 'weight' => 2],
                    ],
                ],
            ],
        ],
    ]);
}

$definition = validDefinition();
$draft = new QuizVersion(
    10,
    100,
    1,
    QuizVersion::STATUS_DRAFT,
    'Historical Quiz',
    $definition,
);

assertVersioning(
    $draft->quizId === 10
        && $draft->id === 100
        && $draft->versionNumber === 1
        && $draft->status === QuizVersion::STATUS_DRAFT
        && $draft->name === 'Historical Quiz',
    'Draft identity and snapshot fields must be preserved.',
);
assertVersioning(
    $draft->isDraft() === true
        && $draft->isPublished() === false
        && $draft->isDiscarded() === false
        && $draft->isEditable() === true,
    'Draft state must be editable only as a domain-state concept.',
);
assertVersioning($draft->definition() === $definition, 'Definition accessor must return the supplied snapshot.');
assertVersioning(isset($draft->definition()->programs['DELTA']), 'A fourth program must remain supported.');

$published = new QuizVersion(
    10,
    101,
    1,
    QuizVersion::STATUS_PUBLISHED,
    'Historical Quiz',
    $definition,
);
assertVersioning(
    $published->isDraft() === false
        && $published->isPublished() === true
        && $published->isDiscarded() === false
        && $published->isEditable() === false,
    'Published state must not be editable.',
);

$discarded = new QuizVersion(
    10,
    102,
    1,
    QuizVersion::STATUS_DISCARDED,
    'Historical Quiz',
    $definition,
);
assertVersioning(
    $discarded->isDraft() === false
        && $discarded->isPublished() === false
        && $discarded->isDiscarded() === true
        && $discarded->isEditable() === false,
    'Discarded state must not be editable.',
);

foreach ([0, -1] as $quizId) {
    expectInvalid(function () use ($quizId, $definition): void {
        new QuizVersion($quizId, 100, 1, QuizVersion::STATUS_DRAFT, 'Historical Quiz', $definition);
    }, 'Invalid quiz identity must be rejected');
}

foreach ([0, -1] as $versionId) {
    expectInvalid(function () use ($versionId, $definition): void {
        new QuizVersion(10, $versionId, 1, QuizVersion::STATUS_DRAFT, 'Historical Quiz', $definition);
    }, 'Invalid version identity must be rejected');
}

foreach ([0, -1] as $versionNumber) {
    expectInvalid(function () use ($versionNumber, $definition): void {
        new QuizVersion(10, 100, $versionNumber, QuizVersion::STATUS_DRAFT, 'Historical Quiz', $definition);
    }, 'Invalid version number must be rejected');
}

foreach (['unknown', 'DRAFT'] as $status) {
    expectInvalid(function () use ($status, $definition): void {
        new QuizVersion(10, 100, 1, $status, 'Historical Quiz', $definition);
    }, 'Unknown or non-lowercase status must be rejected');
}

foreach (['', '   '] as $name) {
    expectInvalid(function () use ($name, $definition): void {
        new QuizVersion(10, 100, 1, QuizVersion::STATUS_DRAFT, $name, $definition);
    }, 'Blank snapshot name must be rejected');
}

$invalidDefinition = new QuizDefinition('Historical Quiz', 1, ['ALPHA' => true], []);
expectInvalid(function () use ($invalidDefinition): void {
    new QuizVersion(10, 100, 1, QuizVersion::STATUS_DRAFT, 'Historical Quiz', $invalidDefinition);
}, 'QuizDefinition validation must be reused');

expectInvalid(function () use ($definition): void {
    new QuizVersion(10, 100, 2, QuizVersion::STATUS_DRAFT, 'Historical Quiz', $definition);
}, 'Definition version mismatch must be rejected');

expectInvalid(function () use ($definition): void {
    new QuizVersion(10, 100, 1, QuizVersion::STATUS_DRAFT, 'Different Name', $definition);
}, 'Definition name mismatch must be rejected');

$score = (new ScoringEngine())->score($draft->definition(), [
    ['question_id' => 'q1', 'option_id' => 'q1a'],
    ['question_id' => 'q2', 'option_id' => 'q2a'],
]);
assertVersioning($score['total_raw_score'] === 5.0, 'Historical definition must remain compatible with G6 scoring.');

$methods = get_class_methods(QuizVersion::class);
assertVersioning(
    array_intersect(['publish', 'discard', 'edit', 'cloneVersion', 'allocateVersion'], $methods) === [],
    'Lifecycle mutation and allocation APIs must remain absent.',
);

echo "Versioning tests passed.\n";
