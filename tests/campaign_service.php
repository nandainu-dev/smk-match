<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\CampaignBatch;
use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\CampaignService;
use App\Core\Config;
use App\Core\Database;
use App\Core\QuizVersionRepository;

const TEST_DATABASE = 'smk_match_g10_r4_test';

function campaignServiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectCampaignServiceRuntime(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

function expectCampaignServicePdoException(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @return list<string> */
function campaignServiceStatements(string $path): array
{
    $contents = file_get_contents($path);
    campaignServiceAssert($contents !== false, 'Migration could not be read: ' . basename($path));

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

function createCampaignServiceFixtureCampaign(
    PDO $connection,
    int $schoolId,
    ?int $quizId,
    ?int $quizVersionId,
    string $name,
): int {
    $statement = $connection->prepare(
        'INSERT INTO campaigns (
            school_id,
            quiz_id,
            quiz_version_id,
            name,
            status,
            created_at,
            updated_at
        ) VALUES (
            :school_id,
            :quiz_id,
            :quiz_version_id,
            :name,
            :status,
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP()
        )'
    );
    $statement->execute([
        'school_id' => $schoolId,
        'quiz_id' => $quizId,
        'quiz_version_id' => $quizVersionId,
        'name' => $name,
        'status' => 'draft',
    ]);

    return (int) $connection->lastInsertId();
}

function createQuizVersionSnapshot(
    PDO $connection,
    int $quizId,
    int $versionNumber,
    string $status,
    string $name,
    int $programId,
): int {
    $versionStatement = $connection->prepare(
        'INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES (:quiz_id, :version_number, :status, :name, UTC_TIMESTAMP())'
    );
    $versionStatement->execute([
        'quiz_id' => $quizId,
        'version_number' => $versionNumber,
        'status' => $status,
        'name' => $name,
    ]);
    $versionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at)
         VALUES ({$versionId}, {$programId}, UTC_TIMESTAMP())"
    );
    $connection->exec(
        "INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at)
         VALUES ({$versionId}, 'Fixture question {$versionId}', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $questionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO question_options (question_id, option_text, sort_order, created_at)
         VALUES
            ({$questionId}, 'Fixture option one', 1, UTC_TIMESTAMP()),
            ({$questionId}, 'Fixture option two', 2, UTC_TIMESTAMP())"
    );
    $optionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO option_weights (question_option_id, program_id, weight, created_at)
         VALUES ({$optionId}, {$programId}, 1, UTC_TIMESTAMP())"
    );

    return $versionId;
}

function countCampaignBatchesForService(PDO $connection, int $campaignId): int
{
    $statement = $connection->prepare(
        'SELECT COUNT(*)
         FROM campaign_batches
         WHERE campaign_id = :campaign_id'
    );
    $statement->execute(['campaign_id' => $campaignId]);

    return (int) $statement->fetchColumn();
}

function countActiveCampaignBatchesForService(PDO $connection, int $campaignId): int
{
    $statement = $connection->prepare(
        'SELECT COUNT(*)
         FROM campaign_batches
         WHERE campaign_id = :campaign_id
           AND active_marker = 1'
    );
    $statement->execute(['campaign_id' => $campaignId]);

    return (int) $statement->fetchColumn();
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

campaignServiceAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === TEST_DATABASE,
    'Unsafe test database configuration.',
);
campaignServiceAssert(
    is_string($user) && $user !== '' && is_string($password),
    'Local database credentials are required.',
);

$admin = null;
try {
    $admin = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $database = new Database(Config::load(SMK_MATCH_ROOT));
    $connection = $database->connection();
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migration) {
        foreach (campaignServiceStatements($migration) as $statement) {
            $connection->exec($statement);
        }
    }

    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('55555555-5555-4555-8555-555555555555', 'Service School A', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolA = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('66666666-6666-4666-8666-666666666666', 'Service School B', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolB = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO programs (school_id, name, short_name, created_at, updated_at)
         VALUES ({$schoolA}, 'Service Program A', 'SERVICE_A', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $programA = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO programs (school_id, name, short_name, created_at, updated_at)
         VALUES ({$schoolB}, 'Service Program B', 'SERVICE_B', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $programB = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolA}, 'Service Quiz A', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizA = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolA}, 'Service Quiz B', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizB = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolB}, 'Service Quiz C', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizC = (int) $connection->lastInsertId();

    $publishedVersionOne = createQuizVersionSnapshot($connection, $quizA, 1, 'published', 'Published version one', $programA);
    $publishedVersionTwo = createQuizVersionSnapshot($connection, $quizA, 2, 'published', 'Published version two', $programA);
    $draftVersion = createQuizVersionSnapshot($connection, $quizA, 3, 'draft', 'Draft version', $programA);
    $wrongQuizVersion = createQuizVersionSnapshot($connection, $quizB, 1, 'published', 'Other quiz version', $programA);
    $wrongSchoolVersion = createQuizVersionSnapshot($connection, $quizC, 1, 'published', 'Other school version', $programB);

    $campaignRepository = new CampaignRepository($database);
    $batchRepository = new CampaignBatchRepository($database);
    $versionRepository = new QuizVersionRepository($database);
    $service = new CampaignService($database, $campaignRepository, $batchRepository, $versionRepository);
    $activationTime = new DateTimeImmutable('2026-03-01 10:30:00', new DateTimeZone('Asia/Jakarta'));
    $resetTime = new DateTimeImmutable('2026-03-01 11:30:00', new DateTimeZone('Asia/Jakarta'));

    $validCampaignId = createCampaignServiceFixtureCampaign(
        $connection,
        $schoolA,
        $quizA,
        $publishedVersionOne,
        'Valid campaign',
    );
    $firstBatch = $service->activateCampaign($validCampaignId, $activationTime);
    campaignServiceAssert(
        $firstBatch->batchNumber === 1
            && $firstBatch->status === CampaignBatch::STATUS_ACTIVE
            && $firstBatch->activeMarker === 1
            && $firstBatch->quizVersionId === $publishedVersionOne
            && $firstBatch->quizVersionId !== $publishedVersionTwo
            && $firstBatch->startedAt === '2026-03-01 03:30:00'
            && $campaignRepository->findById($validCampaignId)?->status === 'active',
        'Valid campaign activation did not create the exact first active snapshot.',
    );
    expectCampaignServiceRuntime(
        static fn () => $service->activateCampaign($validCampaignId, $activationTime),
        'Already active campaign activation was accepted.',
    );

    $nullVersionCampaignId = createCampaignServiceFixtureCampaign($connection, $schoolA, $quizA, null, 'Null version campaign');
    expectCampaignServiceRuntime(
        static fn () => $service->activateCampaign($nullVersionCampaignId, $activationTime),
        'Campaign activation accepted a NULL configured version.',
    );
    campaignServiceAssert(
        countCampaignBatchesForService($connection, $nullVersionCampaignId) === 0
            && $campaignRepository->findById($nullVersionCampaignId)?->status === 'draft',
        'NULL version rejection mutated the campaign.',
    );

    $draftVersionCampaignId = createCampaignServiceFixtureCampaign($connection, $schoolA, $quizA, $draftVersion, 'Draft version campaign');
    expectCampaignServiceRuntime(
        static fn () => $service->activateCampaign($draftVersionCampaignId, $activationTime),
        'Campaign activation accepted a draft configured version.',
    );
    campaignServiceAssert(countCampaignBatchesForService($connection, $draftVersionCampaignId) === 0, 'Draft version rejection created a batch.');

    $wrongQuizCampaignId = createCampaignServiceFixtureCampaign($connection, $schoolA, $quizA, $wrongQuizVersion, 'Wrong quiz campaign');
    expectCampaignServiceRuntime(
        static fn () => $service->activateCampaign($wrongQuizCampaignId, $activationTime),
        'Campaign activation accepted a version from another quiz.',
    );
    campaignServiceAssert(countCampaignBatchesForService($connection, $wrongQuizCampaignId) === 0, 'Wrong quiz rejection created a batch.');

    $wrongSchoolCampaignId = createCampaignServiceFixtureCampaign($connection, $schoolA, $quizC, $wrongSchoolVersion, 'Wrong school campaign');
    expectCampaignServiceRuntime(
        static fn () => $service->activateCampaign($wrongSchoolCampaignId, $activationTime),
        'Campaign activation accepted a version from another school.',
    );
    campaignServiceAssert(countCampaignBatchesForService($connection, $wrongSchoolCampaignId) === 0, 'Wrong school rejection created a batch.');

    $missingVersionCampaignId = createCampaignServiceFixtureCampaign($connection, $schoolA, $quizA, $publishedVersionOne, 'Missing version campaign');
    $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        $connection->exec("UPDATE campaigns SET quiz_version_id = 999999 WHERE id = {$missingVersionCampaignId}");
        expectCampaignServiceRuntime(
            static fn () => $service->activateCampaign($missingVersionCampaignId, $activationTime),
            'Campaign activation accepted a missing configured version.',
        );
    } finally {
        $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    campaignServiceAssert(countCampaignBatchesForService($connection, $missingVersionCampaignId) === 0, 'Missing version rejection created a batch.');

    $existingBatchCampaignId = createCampaignServiceFixtureCampaign($connection, $schoolA, $quizA, $publishedVersionOne, 'Existing batch campaign');
    $batchRepository->create(
        $existingBatchCampaignId,
        1,
        $publishedVersionOne,
        null,
        CampaignBatch::STATUS_ACTIVE,
        1,
        $activationTime,
        null,
    );
    expectCampaignServiceRuntime(
        static fn () => $service->activateCampaign($existingBatchCampaignId, $activationTime),
        'Campaign activation accepted an existing active batch.',
    );

    $noActiveCampaignId = createCampaignServiceFixtureCampaign($connection, $schoolA, $quizA, $publishedVersionOne, 'No active batch campaign');
    campaignServiceAssert(
        $campaignRepository->updateStatus($noActiveCampaignId, 'draft', 'active'),
        'Fixture campaign could not be made active.',
    );
    expectCampaignServiceRuntime(
        static fn () => $service->resetActiveBatch($noActiveCampaignId, $resetTime),
        'Batch reset accepted an active campaign without an active batch.',
    );
    campaignServiceAssert(countCampaignBatchesForService($connection, $noActiveCampaignId) === 0, 'No-active reset rejection created a batch.');

    $secondBatch = $service->resetActiveBatch($validCampaignId, $resetTime);
    $closedFirstBatch = $batchRepository->findById($firstBatch->id);
    campaignServiceAssert(
        $closedFirstBatch !== null
            && $closedFirstBatch->status === CampaignBatch::STATUS_CLOSED
            && $closedFirstBatch->activeMarker === null
            && $closedFirstBatch->closedAt === '2026-03-01 04:30:00'
            && $closedFirstBatch->quizVersionId === $publishedVersionOne
            && $secondBatch->batchNumber === 2
            && $secondBatch->status === CampaignBatch::STATUS_ACTIVE
            && $secondBatch->activeMarker === 1
            && $secondBatch->quizVersionId === $publishedVersionOne
            && countCampaignBatchesForService($connection, $validCampaignId) === 2
            && countActiveCampaignBatchesForService($connection, $validCampaignId) === 1,
        'Active batch reset did not preserve history and create the next exact snapshot.',
    );

    $overflowCampaignId = createCampaignServiceFixtureCampaign($connection, $schoolA, $quizA, $publishedVersionOne, 'Overflow rollback campaign');
    campaignServiceAssert(
        $campaignRepository->updateStatus($overflowCampaignId, 'draft', 'active'),
        'Overflow fixture campaign could not be made active.',
    );
    $maximumBatch = $batchRepository->create(
        $overflowCampaignId,
        4294967295,
        $publishedVersionOne,
        null,
        CampaignBatch::STATUS_ACTIVE,
        1,
        $activationTime,
        null,
    );
    expectCampaignServicePdoException(
        static fn () => $service->resetActiveBatch($overflowCampaignId, $resetTime),
        'Reset did not fail when the next batch number exceeded INT UNSIGNED.',
    );
    $restoredMaximumBatch = $batchRepository->findById($maximumBatch->id);
    campaignServiceAssert(
        $restoredMaximumBatch !== null
            && $restoredMaximumBatch->status === CampaignBatch::STATUS_ACTIVE
            && $restoredMaximumBatch->activeMarker === 1
            && $restoredMaximumBatch->closedAt === null
            && countCampaignBatchesForService($connection, $overflowCampaignId) === 1
            && countActiveCampaignBatchesForService($connection, $overflowCampaignId) === 1,
        'Failed reset did not roll back the closed old batch and partial new batch.',
    );

    echo "Campaign service integration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    }
}
