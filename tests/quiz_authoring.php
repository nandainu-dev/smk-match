<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\AttemptRepository;
use App\Controllers\AdminQuizController;
use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\Config;
use App\Core\Database;
use App\Core\ParticipantQuizDeliveryService;
use App\Core\ParticipantRepository;
use App\Core\QuestionImageStorage;
use App\Core\QuizAuthoringService;
use App\Core\QuizDefinition;
use App\Core\QuizVersionRepository;
use App\Core\QuizVersionService;
use App\Core\ScoringEngine;
use App\Core\VisitorIdentityCookie;
use App\Core\UuidV4Generator;
const QUIZ_AUTHORING_DATABASE = 'smk_match_g13_r2a_authoring_test';

function authoringAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectAuthoringFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message);
}

function applyAllMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $sql = file_get_contents($path);
        authoringAssert($sql !== false, 'Migration could not be read.');

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @return array<string, bool> */
function authoringPrograms(): array
{
    return [
        'ALPHA' => true,
        'BETA' => true,
        'GAMMA' => true,
        'DELTA' => true,
    ];
}

/** @return array<string, bool> */
function simpleAuthoringPrograms(): array
{
    return [
        'ALPHA' => true,
        'BETA' => true,
        'GAMMA' => true,
    ];
}

/**
 * @param array<string, bool> $programs
 * @param list<list<string>> $questionMappings
 */
function simpleMappingDefinition(string $name, array $programs, array $questionMappings): QuizDefinition
{
    $questions = [];
    foreach ($questionMappings as $questionIndex => $mapping) {
        authoringAssert(count($mapping) === 4, 'Simple authoring fixture requires exactly A-D mappings.');
        $options = [];
        foreach ($mapping as $optionIndex => $program) {
            $letter = chr(65 + $optionIndex);
            $options[] = [
                'id' => 'simple-' . $questionIndex . '-' . strtolower($letter),
                'text' => 'Pilihan ' . $letter,
                'order' => ($optionIndex + 1) * 10,
                'weights' => [['program' => $program, 'weight' => 1.0]],
            ];
        }
        $questions[] = [
            'id' => 'simple-' . $questionIndex,
            'text' => 'Pertanyaan ' . ($questionIndex + 1),
            'help_text' => null,
            'image_path' => null,
            'order' => ($questionIndex + 1) * 10,
            'options' => $options,
        ];
    }

    return new QuizDefinition($name, 1, $programs, $questions);
}

function authoringDefinition(string $name, string $prefix, ?string $imagePath = null): QuizDefinition
{
    return new QuizDefinition(
        $name,
        1,
        authoringPrograms(),
        [
            [
                'id' => $prefix . '-later',
                'text' => 'Later question ' . $prefix,
                'image_path' => $imagePath,
                'order' => 20,
                'options' => [
                    [
                        'id' => $prefix . '-later-b',
                        'text' => 'Later option B',
                        'order' => 20,
                        'weights' => [
                            ['program' => 'BETA', 'weight' => 3.5],
                            ['program' => 'DELTA', 'weight' => 1.0],
                        ],
                    ],
                    [
                        'id' => $prefix . '-later-a',
                        'text' => 'Later option A',
                        'order' => 10,
                        'weights' => [
                            ['program' => 'ALPHA', 'weight' => 2.25],
                            ['program' => 'GAMMA', 'weight' => 0.5],
                        ],
                    ],
                ],
            ],
            [
                'id' => $prefix . '-earlier',
                'text' => 'Earlier question ' . $prefix,
                'image_path' => null,
                'order' => 10,
                'options' => [
                    [
                        'id' => $prefix . '-earlier-a',
                        'text' => 'Earlier option A',
                        'order' => 30,
                        'weights' => [
                            ['program' => 'DELTA', 'weight' => 4.0],
                        ],
                    ],
                    [
                        'id' => $prefix . '-earlier-b',
                        'text' => 'Earlier option B',
                        'order' => 10,
                        'weights' => [
                            ['program' => 'GAMMA', 'weight' => 3.0],
                            ['program' => 'ALPHA', 'weight' => 1.0],
                        ],
                    ],
                ],
            ],
        ],
    );
}

/** @param array<string, bool> $programs */
function controllerInitialDefinition(string $quizName, array $programs): QuizDefinition
{
    $reflection = new ReflectionClass(AdminQuizController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(AdminQuizController::class, 'initialDefinition');
    $method->setAccessible(true);
    $definition = $method->invoke($controller, $quizName, $programs);

    authoringAssert($definition instanceof QuizDefinition, 'Initial admin definition was not created.');

    return $definition;
}

/** @param array<string, bool> $programs @return list<array{program: string, weight: float}> */
function controllerDynamicDefaultWeights(array $programs, string $program): array
{
    $reflection = new ReflectionClass(AdminQuizController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $weightInput = new ReflectionMethod(AdminQuizController::class, 'weightInput');
    $weightInput->setAccessible(true);
    $weights = new ReflectionMethod(AdminQuizController::class, 'weights');
    $weights->setAccessible(true);
    authoringAssert(array_key_exists($program, $programs), 'Authoring fixture has no selected program.');

    $input = $weightInput->invoke($controller, [
        'primary_program' => $program,
    ], $programs);
    $assignments = $weights->invoke($controller, $input, $programs);
    authoringAssert(is_array($assignments), 'Dynamic authoring weight conversion did not return assignments.');

    return $assignments;
}

function authoringQuiz(PDO $connection, int $schoolId, string $name): int
{
    $statement = $connection->prepare(
        'INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES (:school_id, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $statement->execute(['school_id' => $schoolId, 'name' => $name]);

    return (int) $connection->lastInsertId();
}

/** @return array<string, int> */
function authoringProgramsForSchool(PDO $connection, int $schoolId): array
{
    $statement = $connection->prepare(
        'INSERT INTO programs (school_id, name, short_name, created_at, updated_at)
         VALUES (:school_id, :name, :short_name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $programIds = [];
    foreach (array_keys(authoringPrograms()) as $code) {
        $statement->execute([
            'school_id' => $schoolId,
            'name' => 'Program ' . $code,
            'short_name' => $code,
        ]);
        $programIds[$code] = (int) $connection->lastInsertId();
    }

    return $programIds;
}

function writeImageFixture(string $directory, string $filename, string $base64): string
{
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Temporary image fixture directory could not be created.');
    }

    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    $written = file_put_contents($path, base64_decode($base64, true));
    authoringAssert($written !== false, 'Image fixture could not be written.');

    return $path;
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            removeDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

/** @return array<string, mixed> */
function authoringPublicQuiz(ParticipantQuizDeliveryService $delivery, QuizDefinition $definition): array
{
    $method = new ReflectionMethod($delivery, 'sanitizeDefinition');
    $method->setAccessible(true);

    return $method->invoke($delivery, $definition);
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
authoringAssert(
    $host === '127.0.0.1'
        && $port === '3306'
        && $databaseName === QUIZ_AUTHORING_DATABASE,
    'Unsafe test database configuration.',
);
authoringAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smk-match-question-images-' . bin2hex(random_bytes(8));
$admin = null;
try {
    $storage = new QuestionImageStorage($temporaryRoot . DIRECTORY_SEPARATOR . 'public');
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL5XQAAAABJRU5ErkJggg==';
    $jpeg = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Ap//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IX//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z';
    $webp = 'UklGRiIAAABXRUJQVlA4IBYAAADQAwCdASoBAAEAAUAmJaQAA3AA/vuUAAA=';

    $storedJpeg = $storage->store(writeImageFixture($temporaryRoot, 'unsafe .. source.jpeg', $jpeg));
    $storedPng = $storage->store(writeImageFixture($temporaryRoot, 'unsafe .. source.png', $png));
    $storedWebp = $storage->store(writeImageFixture($temporaryRoot, 'unsafe .. source.webp', $webp));
    authoringAssert(
        preg_match('#\A/uploads/questions/[a-f0-9]{64}\.jpg\z#D', $storedJpeg) === 1
            && preg_match('#\A/uploads/questions/[a-f0-9]{64}\.png\z#D', $storedPng) === 1
            && preg_match('#\A/uploads/questions/[a-f0-9]{64}\.webp\z#D', $storedWebp) === 1,
        'Question image storage did not generate safe MIME-derived paths.',
    );
    authoringAssert(
        $storedPng !== $storage->store(writeImageFixture($temporaryRoot, 'same-name.png', $png)),
        'Question image storage overwrote an existing generated file.',
    );
    expectAuthoringFailure(
        fn() => $storage->store(writeImageFixture($temporaryRoot, 'fake.png', base64_encode('not an image'))),
        'Fake image data was accepted.',
    );
    expectAuthoringFailure(
        fn() => $storage->store(writeImageFixture($temporaryRoot, 'svg.svg', base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'))),
        'SVG was accepted.',
    );
    $oversizedPath = writeImageFixture($temporaryRoot, 'oversized.png', $png);
    file_put_contents($oversizedPath, str_repeat('x', (5 * 1024 * 1024) + 1), FILE_APPEND);
    expectAuthoringFailure(fn() => $storage->store($oversizedPath), 'Oversized image was accepted.');
    $oversizedDimension = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', 4097) . pack('N', 1) . "\x08\x02\x00\x00\x00" . pack('N', 0);
    expectAuthoringFailure(
        fn() => $storage->store(writeImageFixture($temporaryRoot, 'too-wide.png', base64_encode($oversizedDimension))),
        'Over-dimension image was accepted.',
    );

    $admin = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $admin->exec('DROP DATABASE IF EXISTS ' . QUIZ_AUTHORING_DATABASE);
    $admin->exec('CREATE DATABASE ' . QUIZ_AUTHORING_DATABASE . ' CHARACTER SET utf8mb4');

    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $connection = $database->connection();
    applyAllMigrations($connection);

    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('13000000-0000-4000-8000-000000000011', 'Authoring School A', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolA = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('13000000-0000-4000-8000-000000000012', 'Authoring School B', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolB = (int) $connection->lastInsertId();
    authoringProgramsForSchool($connection, $schoolA);
    authoringProgramsForSchool($connection, $schoolB);

    $repository = new QuizVersionRepository($database);
    $versions = new QuizVersionService($repository);
    $authoring = new QuizAuthoringService($repository);
    $defaultQuizId = authoringQuiz($connection, $schoolA, 'Initial Draft Quiz');
    $simplePrograms = simpleAuthoringPrograms();
    $defaultDefinition = controllerInitialDefinition('Initial Draft Quiz', $simplePrograms);
    $defaultDraft = $repository->createDraftSnapshot($defaultQuizId, $defaultDefinition);
    $reloadedDefaultDraft = $repository->findById($defaultDraft->id);
    authoringAssert(
        $reloadedDefaultDraft !== null
            && count($reloadedDefaultDraft->definition()->questions) === 5,
        'Initial authoring definition did not create five questions.',
    );
    $defaultProgramsByOption = ['ALPHA', 'BETA', 'GAMMA', 'ALPHA'];
    foreach ($reloadedDefaultDraft->definition()->questions as $question) {
        authoringAssert(
            array_column($question['options'], 'text') === ['Pilihan A', 'Pilihan B', 'Pilihan C', 'Pilihan D']
                && count($question['options']) === 4,
            'Initial authoring definition did not retain the canonical A-D option set.',
        );
        foreach ($question['options'] as $optionIndex => $option) {
            $expectedProgram = $defaultProgramsByOption[$optionIndex];
            authoringAssert(
                $option['weights'] === [['program' => $expectedProgram, 'weight' => 1.0]]
                    && $option['weights'] === controllerDynamicDefaultWeights($simplePrograms, $expectedProgram),
                'Server-created default options do not match the independent one-program automatic-weight mapping.',
            );
        }
    }
    $mixedMappings = [
        ['ALPHA', 'BETA', 'GAMMA', 'ALPHA'],
        ['GAMMA', 'ALPHA', 'BETA', 'GAMMA'],
    ];
    $mixedDefinition = simpleMappingDefinition('Initial Draft Quiz', $simplePrograms, $mixedMappings);
    $mixedDraft = $authoring->replaceDraftDefinition($schoolA, $defaultQuizId, $defaultDraft->id, $mixedDefinition);
    $reloadedMixedDraft = $repository->findById($mixedDraft->id);
    authoringAssert($reloadedMixedDraft !== null, 'Mixed program mapping draft could not be reloaded.');
    foreach ($reloadedMixedDraft->definition()->questions as $questionIndex => $question) {
        $actualPrograms = array_map(
            static fn (array $option): string => $option['weights'][0]['program'],
            $question['options'],
        );
        $actualWeights = array_map(
            static fn (array $option): float => $option['weights'][0]['weight'],
            $question['options'],
        );
        authoringAssert(
            $actualPrograms === $mixedMappings[$questionIndex]
                && $actualWeights === [1.0, 1.0, 1.0, 1.0],
            'Independent A-D program mappings were not saved and reloaded exactly with automatic weight one.',
        );
    }
    $sourceQuizId = authoringQuiz($connection, $schoolA, 'Source Quiz');
    $initial = authoringDefinition('Source Draft', 'initial', $storedPng);
    $draft = $repository->createDraftSnapshot($sourceQuizId, $initial);
    $replacement = authoringDefinition('Source Draft Revised', 'revised', $storedJpeg);
    $edited = $authoring->replaceDraftDefinition($schoolA, $sourceQuizId, $draft->id, $replacement);
    $editedQuestions = $edited->definition()->questions;
    authoringAssert(
        $edited->name === 'Source Draft Revised'
            && count($editedQuestions) === 2
            && $editedQuestions[0]['order'] === 10
            && $editedQuestions[0]['text'] === 'Earlier question revised'
            && $editedQuestions[1]['order'] === 20
            && $editedQuestions[1]['image_path'] === $storedJpeg
            && count($editedQuestions[0]['options']) === 2
            && $editedQuestions[0]['options'][0]['order'] === 10
            && $editedQuestions[1]['options'][0]['weights'][0]['program'] === 'ALPHA',
        'Unused draft structure, ordering, image, or N-program weights were not replaced correctly.',
    );
    authoringAssert(
        $connection->query('SELECT COUNT(*) FROM questions WHERE quiz_version_id = ' . $edited->id)->fetchColumn() === 2
            && $connection->query('SELECT COUNT(*) FROM question_options AS qo INNER JOIN questions AS q ON q.id = qo.question_id WHERE q.quiz_version_id = ' . $edited->id)->fetchColumn() === 4
            && $connection->query('SELECT COUNT(*) FROM option_weights AS ow INNER JOIN question_options AS qo ON qo.id = ow.question_option_id INNER JOIN questions AS q ON q.id = qo.question_id WHERE q.quiz_version_id = ' . $edited->id)->fetchColumn() === 7,
        'Question, option, or N-program weight persistence is incomplete.',
    );

    $sameNameDefinition = authoringDefinition('Source Draft Revised', 'same-name', $storedJpeg);
    $sameNameEdited = $authoring->replaceDraftDefinition($schoolA, $sourceQuizId, $edited->id, $sameNameDefinition);
    authoringAssert(
        $sameNameEdited->name === 'Source Draft Revised'
            && $sameNameEdited->definition()->questions[0]['text'] === 'Earlier question same-name',
        'A draft save with an unchanged name was treated as a lifecycle conflict.',
    );

    $beforeRollback = $repository->findById($edited->id);
    authoringAssert($beforeRollback !== null, 'Draft could not be reloaded before rollback test.');
    $failingName = str_repeat('x', 256);
    $failingDefinition = authoringDefinition($failingName, 'failure', $storedJpeg);
    expectAuthoringFailure(
        fn() => $authoring->replaceDraftDefinition($schoolA, $sourceQuizId, $edited->id, $failingDefinition),
        'Database write failure was not raised.',
    );
    $afterRollback = $repository->findById($edited->id);
    authoringAssert(
        $afterRollback !== null
            && $afterRollback->name === $beforeRollback->name
            && $afterRollback->definition()->questions === $beforeRollback->definition()->questions,
        'Failed authoring write did not roll back the original structure.',
    );

    $published = $versions->publish($sourceQuizId, $edited->id);
    expectAuthoringFailure(
        fn() => $authoring->replaceDraftDefinition($schoolA, $sourceQuizId, $published->id, $replacement),
        'Published version accepted structure mutation.',
    );
    $clone = $versions->cloneVersionToDraft($sourceQuizId, $published->id);
    authoringAssert(
        $clone->isDraft()
            && $clone->definition()->questions[1]['image_path'] === $storedJpeg
            && $repository->findById($published->id)?->definition()->questions[1]['image_path'] === $storedJpeg,
        'Clone did not retain the immutable image reference or changed the source version.',
    );

    $versions->discard($sourceQuizId, $clone->id);
    $freshPresentationClone = $versions->cloneVersionToDraftWithCurrentPresentations($sourceQuizId, $published->id);
    authoringAssert(
        $freshPresentationClone->isDraft()
            && $freshPresentationClone->definition()->questions[1]['image_path'] === $storedJpeg
            && $repository->findById($published->id)?->definition()->questions[1]['image_path'] === $storedJpeg,
        'Fresh presentation clone did not preserve the source question structure.',
    );

    $discardQuizId = authoringQuiz($connection, $schoolA, 'Discard Quiz');
    $discardDraft = $repository->createDraftSnapshot($discardQuizId, authoringDefinition('Discard Draft', 'discard'));
    $discarded = $versions->discard($discardQuizId, $discardDraft->id);
    expectAuthoringFailure(
        fn() => $authoring->replaceDraftDefinition($schoolA, $discardQuizId, $discarded->id, authoringDefinition('Discard Edit', 'discard-edit')),
        'Discarded version accepted structure mutation.',
    );

    $usedQuizId = authoringQuiz($connection, $schoolA, 'Used Quiz');
    $usedDraft = $repository->createDraftSnapshot($usedQuizId, authoringDefinition('Used Draft', 'used'));
    $connection->prepare(
        'INSERT INTO attempts (quiz_version_id, visitor_uuid, attempt_uuid, status, created_at)
         VALUES (:version_id, :visitor_uuid, :attempt_uuid, :status, UTC_TIMESTAMP())'
    )->execute([
        'version_id' => $usedDraft->id,
        'visitor_uuid' => '13000000-0000-4000-8000-000000000021',
        'attempt_uuid' => '13000000-0000-4000-8000-000000000022',
        'status' => 'started',
    ]);
    expectAuthoringFailure(
        fn() => $authoring->replaceDraftDefinition($schoolA, $usedQuizId, $usedDraft->id, authoringDefinition('Used Edit', 'used-edit')),
        'Used draft accepted structure mutation.',
    );

    $schoolBQuizId = authoringQuiz($connection, $schoolB, 'School B Quiz');
    $schoolBDraft = $repository->createDraftSnapshot($schoolBQuizId, authoringDefinition('School B Draft', 'school-b'));
    expectAuthoringFailure(
        fn() => $authoring->replaceDraftDefinition($schoolA, $schoolBQuizId, $schoolBDraft->id, authoringDefinition('Cross School', 'cross-school')),
        'Cross-school draft mutation was accepted.',
    );

    $invalidPrograms = authoringDefinition('Invalid Weight', 'invalid-weight');
    $invalidQuestions = $invalidPrograms->questions;
    $invalidQuestions[0]['options'][0]['weights'][] = ['program' => 'OUTSIDE', 'weight' => 1];
    $invalidWeight = new QuizDefinition('Invalid Weight', 1, authoringPrograms(), $invalidQuestions);
    expectAuthoringFailure(
        fn() => $authoring->replaceDraftDefinition($schoolA, $sourceQuizId, $clone->id, $invalidWeight),
        'Out-of-membership program weight was accepted.',
    );
    $membershipPrograms = authoringPrograms();
    $membershipPrograms['OUTSIDE'] = true;
    $invalidMembership = new QuizDefinition(
        'Invalid Membership',
        1,
        $membershipPrograms,
        authoringDefinition('Invalid Membership', 'invalid-membership')->questions,
    );
    expectAuthoringFailure(
        fn() => $authoring->replaceDraftDefinition($schoolA, $sourceQuizId, $clone->id, $invalidMembership),
        'Definition with a program outside the version snapshot membership was accepted.',
    );

    $delivery = new ParticipantQuizDeliveryService(
        new AttemptRepository($database),
        new ParticipantRepository($database),
        new CampaignRepository($database),
        new CampaignBatchRepository($database),
        $repository,
        new VisitorIdentityCookie(new UuidV4Generator()),
    );
    $publicWithImage = authoringPublicQuiz($delivery, $edited->definition());
    $publicWithoutImage = authoringPublicQuiz($delivery, authoringDefinition('No Image', 'no-image'));
    authoringAssert(
        $publicWithImage['questions'][1]['image_path'] === $storedJpeg
            && !array_key_exists('weights', $publicWithImage['questions'][1]['options'][0])
            && !array_key_exists('image_path', $publicWithoutImage['questions'][0]),
        'Participant image delivery leaked scoring data or changed null-image compatibility.',
    );

    $scoring = new ScoringEngine();
    $withoutImage = authoringDefinition('Score Contract', 'score');
    $withImage = authoringDefinition('Score Contract', 'score', $storedPng);
    $answers = [
        ['question_id' => 'score-later', 'option_id' => 'score-later-a'],
        ['question_id' => 'score-earlier', 'option_id' => 'score-earlier-b'],
    ];
    authoringAssert(
        $scoring->score($withoutImage, $answers) === $scoring->score($withImage, $answers),
        'Question image metadata changed scoring semantics.',
    );

    echo "Quiz authoring tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . QUIZ_AUTHORING_DATABASE);
    }

    removeDirectory($temporaryRoot);
    putenv('DB_PASSWORD');
}
