<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\QuizDefinition;
use App\Core\QuizVersion;
use App\Core\QuizVersionRepository;
use App\Core\QuizVersionService;

const TEST_DATABASE = 'smk_match_g7_service_test';

function serviceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectServiceRuntime(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

function expectServiceInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function serviceDefinition(string $name, string $marker, int $sourceVersion = 77): QuizDefinition
{
    return new QuizDefinition($name, $sourceVersion, [
        'ALPHA' => true,
        'BETA' => true,
        'GAMMA' => true,
        'DELTA' => true,
    ], [
        [
            'id' => 'q1',
            'text' => $marker . ' question one',
            'order' => 1,
            'options' => [
                [
                    'id' => 'q1a',
                    'text' => $marker . ' option one',
                    'order' => 1,
                    'weights' => [
                        ['program' => 'ALPHA', 'weight' => 0.333],
                        ['program' => 'BETA', 'weight' => 1.257],
                    ],
                ],
                [
                    'id' => 'q1b',
                    'text' => $marker . ' option two',
                    'order' => 2,
                    'weights' => [
                        ['program' => 'GAMMA', 'weight' => 0.000001],
                    ],
                ],
            ],
        ],
        [
            'id' => 'q2',
            'text' => $marker . ' question two',
            'order' => 2,
            'options' => [
                [
                    'id' => 'q2a',
                    'text' => $marker . ' option three',
                    'order' => 1,
                    'weights' => [
                        ['program' => 'DELTA', 'weight' => -1.25],
                    ],
                ],
                [
                    'id' => 'q2b',
                    'text' => $marker . ' option four',
                    'order' => 2,
                    'weights' => [],
                ],
            ],
        ],
    ]);
}

function createQuiz(PDO $connection, int $schoolId, string $name): int
{
    $statement = $connection->prepare(
        'INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES (:school_id, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $statement->execute([
        'school_id' => $schoolId,
        'name' => $name,
    ]);

    return (int) $connection->lastInsertId();
}

function insertAttempt(PDO $connection, int $versionId, int $sequence): void
{
    $statement = $connection->prepare(
        'INSERT INTO attempts (quiz_version_id, visitor_uuid, attempt_uuid, status, created_at)
         VALUES (:quiz_version_id, :visitor_uuid, :attempt_uuid, :status, UTC_TIMESTAMP())'
    );
    $statement->execute([
        'quiz_version_id' => $versionId,
        'visitor_uuid' => sprintf('%08d-0000-4000-8000-%012d', $sequence, $sequence),
        'attempt_uuid' => sprintf('%08d-0000-4000-9000-%012d', $sequence, $sequence),
        'status' => 'started',
    ]);
}

function versionGraphCounts(PDO $connection, int $versionId): array
{
    $queries = [
        'versions' => 'SELECT COUNT(*) FROM quiz_versions WHERE id = :version_id',
        'memberships' => 'SELECT COUNT(*) FROM quiz_version_programs WHERE quiz_version_id = :version_id',
        'questions' => 'SELECT COUNT(*) FROM questions WHERE quiz_version_id = :version_id',
        'options' => 'SELECT COUNT(*) FROM question_options AS qo INNER JOIN questions AS q ON q.id = qo.question_id WHERE q.quiz_version_id = :version_id',
        'weights' => 'SELECT COUNT(*) FROM option_weights AS ow INNER JOIN question_options AS qo ON qo.id = ow.question_option_id INNER JOIN questions AS q ON q.id = qo.question_id WHERE q.quiz_version_id = :version_id',
    ];
    $counts = [];

    foreach ($queries as $name => $sql) {
        $statement = $connection->prepare($sql);
        $statement->execute(['version_id' => $versionId]);
        $counts[$name] = (int) $statement->fetchColumn();
    }

    return $counts;
}

function assertVersionGraphCounts(array $actual, array $expected, string $context): void
{
    foreach ($expected as $name => $count) {
        serviceAssert(
            array_key_exists($name, $actual) && $actual[$name] === $count,
            $context . ': unexpected ' . $name . ' count.',
        );
    }
}

function countVersionsForQuiz(PDO $connection, int $quizId): int
{
    $statement = $connection->prepare('SELECT COUNT(*) FROM quiz_versions WHERE quiz_id = :quiz_id');
    $statement->execute(['quiz_id' => $quizId]);

    return (int) $statement->fetchColumn();
}

function persistedStatus(PDO $connection, int $versionId): string
{
    $statement = $connection->prepare('SELECT status FROM quiz_versions WHERE id = :version_id');
    $statement->execute(['version_id' => $versionId]);

    return (string) $statement->fetchColumn();
}

function persistedPublishedAt(PDO $connection, int $versionId): ?string
{
    $statement = $connection->prepare('SELECT published_at FROM quiz_versions WHERE id = :version_id');
    $statement->execute(['version_id' => $versionId]);
    $value = $statement->fetchColumn();

    return $value === null ? null : (string) $value;
}

function contentFingerprint(QuizVersion $version): string
{
    $definition = $version->definition();
    $programs = array_keys($definition->programs);
    sort($programs);
    $questions = [];

    foreach ($definition->questions as $question) {
        $options = [];
        foreach ($question['options'] as $option) {
            $weights = [];
            foreach ($option['weights'] as $assignment) {
                $weights[$assignment['program']] = (float) $assignment['weight'];
            }
            ksort($weights);
            $options[] = [
                'text' => $option['text'],
                'order' => $option['order'],
                'weights' => $weights,
            ];
        }
        $questions[] = [
            'text' => $question['text'],
            'order' => $question['order'],
            'options' => $options,
        ];
    }

    return serialize([
        'name' => $version->name,
        'programs' => $programs,
        'questions' => $questions,
    ]);
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
serviceAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === TEST_DATABASE,
    'Unsafe test database configuration.',
);
serviceAssert(
    is_string($user) && $user !== '' && is_string($password),
    'Local database credentials are required.',
);

$admin = null;
try {
    $admin = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $applicationPdo = $database->connection();
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migration) {
        $sql = file_get_contents($migration);
        serviceAssert($sql !== false, 'Migration could not be read.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $applicationPdo->exec($statement);
        }
    }

    $fixturePdo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=' . TEST_DATABASE . ';charset=utf8mb4',
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $insertSchool = $fixturePdo->prepare(
        'INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES (:public_uuid, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $insertSchool->execute([
        'public_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'name' => 'Service Test School',
    ]);
    $schoolId = (int) $fixturePdo->lastInsertId();
    $insertProgram = $fixturePdo->prepare(
        'INSERT INTO programs (school_id, name, short_name, is_active, created_at, updated_at)
         VALUES (:school_id, :name, :short_name, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    foreach (['ALPHA', 'BETA', 'GAMMA', 'DELTA'] as $program) {
        $insertProgram->execute([
            'school_id' => $schoolId,
            'name' => $program,
            'short_name' => $program,
        ]);
    }

    $repository = new QuizVersionRepository($database);
    $service = new QuizVersionService($repository);
    $definition = serviceDefinition('First draft name', 'first');

    foreach ([0, -1] as $invalidId) {
        expectServiceInvalid(
            fn() => $service->getOrCreateDraft($invalidId, $definition),
            'getOrCreateDraft accepted an invalid quiz identity.',
        );
        expectServiceInvalid(
            fn() => $service->cloneVersionToDraft($invalidId, 1),
            'cloneVersionToDraft accepted an invalid quiz identity.',
        );
        expectServiceInvalid(
            fn() => $service->cloneVersionToDraft(1, $invalidId),
            'cloneVersionToDraft accepted an invalid source identity.',
        );
        expectServiceInvalid(
            fn() => $service->publish($invalidId, 1),
            'publish accepted an invalid quiz identity.',
        );
        expectServiceInvalid(
            fn() => $service->publish(1, $invalidId),
            'publish accepted an invalid version identity.',
        );
        expectServiceInvalid(
            fn() => $service->discard($invalidId, 1),
            'discard accepted an invalid quiz identity.',
        );
        expectServiceInvalid(
            fn() => $service->discard(1, $invalidId),
            'discard accepted an invalid version identity.',
        );
    }

    $firstQuiz = createQuiz($fixturePdo, $schoolId, 'First and reuse');
    $firstDraft = $service->getOrCreateDraft($firstQuiz, $definition);
    serviceAssert(
        $firstDraft->isDraft()
            && $firstDraft->versionNumber === 1
            && $firstDraft->versionNumber !== $definition->version
            && $firstDraft->name === $definition->name,
        'First draft creation did not preserve the expected lifecycle and snapshot identity.',
    );
    $firstFingerprint = contentFingerprint($firstDraft);
    serviceAssert(countVersionsForQuiz($fixturePdo, $firstQuiz) === 1, 'First draft was not persisted exactly once.');
    $reusedDraft = $service->getOrCreateDraft(
        $firstQuiz,
        serviceDefinition('Replacement seed must be ignored', 'replacement'),
    );
    serviceAssert(
        $reusedDraft->id === $firstDraft->id
            && $reusedDraft->versionNumber === $firstDraft->versionNumber
            && $reusedDraft->name === $firstDraft->name
            && contentFingerprint($reusedDraft) === $firstFingerprint,
        'Existing draft was not reused exactly.',
    );
    serviceAssert(countVersionsForQuiz($fixturePdo, $firstQuiz) === 1, 'Draft reuse created an extra version.');

    $usedDraftQuiz = createQuiz($fixturePdo, $schoolId, 'Used draft reuse');
    $usedDraft = $service->getOrCreateDraft($usedDraftQuiz, serviceDefinition('Used draft', 'used-draft'));
    insertAttempt($fixturePdo, $usedDraft->id, 1);
    $usedDraftGraph = versionGraphCounts($fixturePdo, $usedDraft->id);
    expectServiceRuntime(
        fn() => $service->getOrCreateDraft($usedDraftQuiz, serviceDefinition('Ignored used seed', 'used-replacement')),
        'Used draft was returned as editable.',
    );
    serviceAssert(countVersionsForQuiz($fixturePdo, $usedDraftQuiz) === 1, 'Used draft received a replacement version.');
    assertVersionGraphCounts(versionGraphCounts($fixturePdo, $usedDraft->id), $usedDraftGraph, 'Used draft reuse rejection');

    $existingCloneQuiz = createQuiz($fixturePdo, $schoolId, 'Existing draft clone');
    $existingCloneSource = $service->getOrCreateDraft($existingCloneQuiz, serviceDefinition('Existing clone source', 'existing-source'));
    $service->publish($existingCloneQuiz, $existingCloneSource->id, new DateTimeImmutable('2026-01-01 08:00:00', new DateTimeZone('Asia/Jakarta')));
    $existingCloneDraft = $service->getOrCreateDraft($existingCloneQuiz, serviceDefinition('Existing clone draft', 'existing-draft'));
    $existingSourceBefore = contentFingerprint($repository->findById($existingCloneSource->id));
    $existingDraftBefore = contentFingerprint($repository->findById($existingCloneDraft->id));
    expectServiceRuntime(
        fn() => $service->cloneVersionToDraft($existingCloneQuiz, $existingCloneSource->id),
        'Explicit clone replaced an existing draft.',
    );
    serviceAssert(countVersionsForQuiz($fixturePdo, $existingCloneQuiz) === 2, 'Explicit clone created a version despite an existing draft.');
    serviceAssert(contentFingerprint($repository->findById($existingCloneSource->id)) === $existingSourceBefore, 'Explicit clone changed its historical source.');
    serviceAssert(contentFingerprint($repository->findById($existingCloneDraft->id)) === $existingDraftBefore, 'Explicit clone changed the existing draft.');

    $publishedCloneQuiz = createQuiz($fixturePdo, $schoolId, 'Published clone');
    $publishedSource = $service->getOrCreateDraft($publishedCloneQuiz, serviceDefinition('Published source', 'published-source'));
    $publishedSourceGraph = versionGraphCounts($fixturePdo, $publishedSource->id);
    $publishedSourceFingerprint = contentFingerprint($publishedSource);
    $service->publish($publishedCloneQuiz, $publishedSource->id, new DateTimeImmutable('2026-01-02 08:00:00', new DateTimeZone('Asia/Jakarta')));
    $publishedClone = $service->cloneVersionToDraft($publishedCloneQuiz, $publishedSource->id);
    serviceAssert(
        $publishedClone->isDraft()
            && $publishedClone->id !== $publishedSource->id
            && $publishedClone->versionNumber === 2
            && $publishedClone->versionNumber !== $publishedSource->versionNumber
            && contentFingerprint($publishedClone) === $publishedSourceFingerprint,
        'Published historical version was not cloned as an independent draft.',
    );
    $reloadedPublishedSource = $repository->findById($publishedSource->id);
    serviceAssert(
        $reloadedPublishedSource !== null
            && $reloadedPublishedSource->isPublished()
            && $reloadedPublishedSource->versionNumber === 1
            && contentFingerprint($reloadedPublishedSource) === $publishedSourceFingerprint,
        'Published clone changed its source snapshot.',
    );
    assertVersionGraphCounts(versionGraphCounts($fixturePdo, $publishedSource->id), $publishedSourceGraph, 'Published source immutability');

    $olderCloneQuiz = createQuiz($fixturePdo, $schoolId, 'Explicit older clone');
    $olderVersionOne = $service->getOrCreateDraft($olderCloneQuiz, serviceDefinition('Older version one', 'older-one'));
    $olderOneFingerprint = contentFingerprint($olderVersionOne);
    $service->publish($olderCloneQuiz, $olderVersionOne->id, new DateTimeImmutable('2026-01-03 08:00:00', new DateTimeZone('Asia/Jakarta')));
    $olderVersionTwo = $service->getOrCreateDraft($olderCloneQuiz, serviceDefinition('Later version two', 'later-two'));
    $service->publish($olderCloneQuiz, $olderVersionTwo->id, new DateTimeImmutable('2026-01-04 08:00:00', new DateTimeZone('Asia/Jakarta')));
    $olderVersionThree = $service->getOrCreateDraft($olderCloneQuiz, serviceDefinition('Latest version three', 'latest-three'));
    $olderThreeFingerprint = contentFingerprint($olderVersionThree);
    $service->publish($olderCloneQuiz, $olderVersionThree->id, new DateTimeImmutable('2026-01-05 08:00:00', new DateTimeZone('Asia/Jakarta')));
    $olderClone = $service->cloneVersionToDraft($olderCloneQuiz, $olderVersionOne->id);
    serviceAssert(
        $olderClone->versionNumber === 4
            && contentFingerprint($olderClone) === $olderOneFingerprint
            && contentFingerprint($olderClone) !== $olderThreeFingerprint,
        'Explicit older clone used a latest-version snapshot instead of the selected source.',
    );

    $discardedCloneQuiz = createQuiz($fixturePdo, $schoolId, 'Discarded clone');
    $discardedSource = $service->getOrCreateDraft($discardedCloneQuiz, serviceDefinition('Discarded source', 'discarded-source'));
    $discardedSourceFingerprint = contentFingerprint($discardedSource);
    $discarded = $service->discard($discardedCloneQuiz, $discardedSource->id);
    serviceAssert($discarded->isDiscarded(), 'Unused draft could not be discarded before clone.');
    $discardedClone = $service->cloneVersionToDraft($discardedCloneQuiz, $discardedSource->id);
    serviceAssert(
        $discardedClone->isDraft()
            && $discardedClone->versionNumber === 2
            && contentFingerprint($discardedClone) === $discardedSourceFingerprint
            && $repository->findById($discardedSource->id)?->isDiscarded() === true,
        'Discarded historical source could not be cloned without mutation.',
    );

    $ownershipQuiz = createQuiz($fixturePdo, $schoolId, 'Ownership target');
    serviceAssert(countVersionsForQuiz($fixturePdo, $ownershipQuiz) === 0, 'Ownership target was not empty.');
    expectServiceRuntime(
        fn() => $service->cloneVersionToDraft($ownershipQuiz, $publishedSource->id),
        'Clone accepted a source from another quiz.',
    );
    expectServiceRuntime(
        fn() => $service->cloneVersionToDraft($ownershipQuiz, 999999),
        'Clone accepted a missing source version.',
    );
    serviceAssert(countVersionsForQuiz($fixturePdo, $ownershipQuiz) === 0, 'Invalid clone attempts created a target version.');

    $publishSuccessQuiz = createQuiz($fixturePdo, $schoolId, 'Publish success');
    $publishSuccessDraft = $service->getOrCreateDraft($publishSuccessQuiz, serviceDefinition('Publish success', 'publish-success'));
    $publishSuccessFingerprint = contentFingerprint($publishSuccessDraft);
    $publishedAt = new DateTimeImmutable('2026-02-03 10:15:30', new DateTimeZone('Asia/Jakarta'));
    $publishedSuccess = $service->publish($publishSuccessQuiz, $publishSuccessDraft->id, $publishedAt);
    serviceAssert(
        $publishedSuccess->isPublished()
            && persistedStatus($fixturePdo, $publishSuccessDraft->id) === QuizVersion::STATUS_PUBLISHED
            && persistedPublishedAt($fixturePdo, $publishSuccessDraft->id) === $publishedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            && contentFingerprint($publishedSuccess) === $publishSuccessFingerprint,
        'Publish success did not persist the expected immutable snapshot state.',
    );
    expectServiceRuntime(
        fn() => $service->publish($publishSuccessQuiz, $publishSuccessDraft->id),
        'Published version was published again.',
    );
    serviceAssert(persistedStatus($fixturePdo, $publishSuccessDraft->id) === QuizVersion::STATUS_PUBLISHED, 'Second publish changed published status.');

    $defaultTimestampQuiz = createQuiz($fixturePdo, $schoolId, 'Publish default timestamp');
    $defaultTimestampDraft = $service->getOrCreateDraft($defaultTimestampQuiz, serviceDefinition('Default timestamp', 'default-timestamp'));
    $beforePublish = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $service->publish($defaultTimestampQuiz, $defaultTimestampDraft->id);
    $afterPublish = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $persistedDefaultTimestamp = persistedPublishedAt($fixturePdo, $defaultTimestampDraft->id);
    serviceAssert($persistedDefaultTimestamp !== null, 'Default publish timestamp was not persisted.');
    $persistedDefaultInstant = new DateTimeImmutable($persistedDefaultTimestamp, new DateTimeZone('UTC'));
    serviceAssert(
        $persistedDefaultInstant >= $beforePublish->modify('-1 second')
            && $persistedDefaultInstant <= $afterPublish->modify('+1 second'),
        'Default publish timestamp was outside the UTC call window.',
    );

    $publishDiscardedQuiz = createQuiz($fixturePdo, $schoolId, 'Publish discarded');
    $publishDiscardedDraft = $service->getOrCreateDraft($publishDiscardedQuiz, serviceDefinition('Publish discarded', 'publish-discarded'));
    $service->discard($publishDiscardedQuiz, $publishDiscardedDraft->id);
    expectServiceRuntime(
        fn() => $service->publish($publishDiscardedQuiz, $publishDiscardedDraft->id),
        'Discarded version was published.',
    );
    serviceAssert(persistedStatus($fixturePdo, $publishDiscardedDraft->id) === QuizVersion::STATUS_DISCARDED, 'Publish changed discarded status.');

    $publishUsedQuiz = createQuiz($fixturePdo, $schoolId, 'Publish used draft');
    $publishUsedDraft = $service->getOrCreateDraft($publishUsedQuiz, serviceDefinition('Publish used draft', 'publish-used'));
    insertAttempt($fixturePdo, $publishUsedDraft->id, 2);
    expectServiceRuntime(
        fn() => $service->publish($publishUsedQuiz, $publishUsedDraft->id),
        'Used draft was published.',
    );
    serviceAssert(
        persistedStatus($fixturePdo, $publishUsedDraft->id) === QuizVersion::STATUS_DRAFT
            && persistedPublishedAt($fixturePdo, $publishUsedDraft->id) === null,
        'Publish used-draft rejection mutated persisted state.',
    );
    expectServiceRuntime(
        fn() => $service->publish($ownershipQuiz, $publishSuccessDraft->id),
        'Publish accepted a version from another quiz.',
    );
    expectServiceRuntime(
        fn() => $service->publish($ownershipQuiz, 999998),
        'Publish accepted a missing version.',
    );

    $discardSuccessQuiz = createQuiz($fixturePdo, $schoolId, 'Discard success');
    $discardSuccessDraft = $service->getOrCreateDraft($discardSuccessQuiz, serviceDefinition('Discard success', 'discard-success'));
    $discardSuccessGraph = versionGraphCounts($fixturePdo, $discardSuccessDraft->id);
    $discardedSuccess = $service->discard($discardSuccessQuiz, $discardSuccessDraft->id);
    serviceAssert(
        $discardedSuccess->isDiscarded()
            && persistedStatus($fixturePdo, $discardSuccessDraft->id) === QuizVersion::STATUS_DISCARDED
            && persistedPublishedAt($fixturePdo, $discardSuccessDraft->id) === null,
        'Discard success did not persist a discarded tombstone.',
    );
    assertVersionGraphCounts(versionGraphCounts($fixturePdo, $discardSuccessDraft->id), $discardSuccessGraph, 'Discard must not hard-delete the snapshot graph');
    expectServiceRuntime(
        fn() => $service->discard($discardSuccessQuiz, $discardSuccessDraft->id),
        'Discarded version was discarded again.',
    );
    serviceAssert(persistedStatus($fixturePdo, $discardSuccessDraft->id) === QuizVersion::STATUS_DISCARDED, 'Repeated discard changed discarded status.');

    $discardUsedQuiz = createQuiz($fixturePdo, $schoolId, 'Discard used draft');
    $discardUsedDraft = $service->getOrCreateDraft($discardUsedQuiz, serviceDefinition('Discard used draft', 'discard-used'));
    insertAttempt($fixturePdo, $discardUsedDraft->id, 3);
    expectServiceRuntime(
        fn() => $service->discard($discardUsedQuiz, $discardUsedDraft->id),
        'Used draft was discarded.',
    );
    serviceAssert(
        persistedStatus($fixturePdo, $discardUsedDraft->id) === QuizVersion::STATUS_DRAFT
            && countVersionsForQuiz($fixturePdo, $discardUsedQuiz) === 1,
        'Discard used-draft rejection changed persisted state.',
    );
    expectServiceRuntime(
        fn() => $service->discard($ownershipQuiz, $publishSuccessDraft->id),
        'Discard accepted a version from another quiz.',
    );
    expectServiceRuntime(
        fn() => $service->discard($ownershipQuiz, 999997),
        'Discard accepted a missing version.',
    );
    expectServiceRuntime(
        fn() => $service->discard($publishSuccessQuiz, $publishSuccessDraft->id),
        'Published version was discarded.',
    );
    serviceAssert(persistedStatus($fixturePdo, $publishSuccessDraft->id) === QuizVersion::STATUS_PUBLISHED, 'Discard changed published status.');

    echo "Quiz version service integration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    }
    putenv('DB_PASSWORD');
}
