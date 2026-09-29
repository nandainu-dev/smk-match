<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\CampaignBatch;
use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\Config;
use App\Core\Database;

const TEST_DATABASE = 'smk_match_g10_r3_test';

function campaignRepositoryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectCampaignRepositoryPdoException(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

function expectCampaignRepositoryLogicException(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (LogicException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @return list<string> */
function campaignRepositoryStatements(string $path): array
{
    $contents = file_get_contents($path);
    campaignRepositoryAssert($contents !== false, 'Migration could not be read: ' . basename($path));

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

function insertCampaign(
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
            starts_at,
            ends_at,
            created_at,
            updated_at
        ) VALUES (
            :school_id,
            :quiz_id,
            :quiz_version_id,
            :name,
            :status,
            :starts_at,
            :ends_at,
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
        'starts_at' => '2026-01-01 00:00:00',
        'ends_at' => '2026-01-31 23:59:59',
    ]);

    return (int) $connection->lastInsertId();
}

function countCampaignBatches(PDO $connection, int $campaignId): int
{
    $statement = $connection->prepare(
        'SELECT COUNT(*)
         FROM campaign_batches
         WHERE campaign_id = :campaign_id'
    );
    $statement->execute(['campaign_id' => $campaignId]);

    return (int) $statement->fetchColumn();
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

campaignRepositoryAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === TEST_DATABASE,
    'Unsafe test database configuration.',
);
campaignRepositoryAssert(
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
        foreach (campaignRepositoryStatements($migration) as $statement) {
            $connection->exec($statement);
        }
    }

    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('44444444-4444-4444-8444-444444444444', 'Repository School', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolId}, 'Repository Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES ({$quizId}, 1, 'published', 'Repository Quiz Version One', UTC_TIMESTAMP())"
    );
    $versionOne = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES ({$quizId}, 2, 'published', 'Repository Quiz Version Two', UTC_TIMESTAMP())"
    );
    $versionTwo = (int) $connection->lastInsertId();

    $nullVersionCampaignId = insertCampaign($connection, $schoolId, $quizId, null, 'Null version campaign');
    $configuredCampaignId = insertCampaign($connection, $schoolId, $quizId, $versionOne, 'Configured version campaign');
    $emptyCampaignId = insertCampaign($connection, $schoolId, $quizId, null, 'Empty batch campaign');
    $batchCampaignId = insertCampaign($connection, $schoolId, $quizId, $versionOne, 'Batch repository campaign');

    $campaignRepository = new CampaignRepository($database);
    $batchRepository = new CampaignBatchRepository($database);

    $nullVersionCampaign = $campaignRepository->findById($nullVersionCampaignId);
    campaignRepositoryAssert(
        $nullVersionCampaign !== null
            && $nullVersionCampaign->quizVersionId === null
            && $nullVersionCampaign->quizId === $quizId
            && $nullVersionCampaign->startsAt === '2026-01-01 00:00:00'
            && $nullVersionCampaign->endsAt === '2026-01-31 23:59:59',
        'Campaign repository did not preserve a NULL configured version.',
    );
    $configuredCampaign = $campaignRepository->findById($configuredCampaignId);
    campaignRepositoryAssert(
        $configuredCampaign !== null && $configuredCampaign->quizVersionId === $versionOne,
        'Campaign repository did not preserve the exact configured version.',
    );
    campaignRepositoryAssert(
        $configuredCampaign->quizVersionId !== $versionTwo,
        'Campaign repository guessed the latest quiz version.',
    );
    campaignRepositoryAssert(
        $campaignRepository->findById(999999) === null,
        'Campaign repository returned a missing campaign.',
    );
    campaignRepositoryAssert(
        $campaignRepository->updateQuizVersionId($configuredCampaignId, $versionTwo),
        'Campaign repository did not apply an explicit quiz version update.',
    );
    campaignRepositoryAssert(
        $campaignRepository->findById($configuredCampaignId)?->quizVersionId === $versionTwo,
        'Campaign repository did not retain the explicitly updated version.',
    );
    campaignRepositoryAssert(
        $campaignRepository->updateQuizVersionId($configuredCampaignId, null),
        'Campaign repository did not clear an explicitly configured version.',
    );
    campaignRepositoryAssert(
        $campaignRepository->findById($configuredCampaignId)?->quizVersionId === null,
        'Campaign repository did not preserve a cleared version as NULL.',
    );
    expectCampaignRepositoryPdoException(
        static fn () => $campaignRepository->updateQuizVersionId($configuredCampaignId, 999999),
        'Campaign repository accepted an invalid quiz version foreign key.',
    );

    expectCampaignRepositoryLogicException(
        static fn () => $campaignRepository->findByIdForUpdate($batchCampaignId),
        'Campaign lock lookup accepted a missing caller transaction.',
    );
    $connection->beginTransaction();
    try {
        campaignRepositoryAssert(
            $campaignRepository->findByIdForUpdate($batchCampaignId)?->id === $batchCampaignId,
            'Campaign lock lookup did not return the requested campaign.',
        );
    } finally {
        $connection->rollBack();
    }

    campaignRepositoryAssert(
        $batchRepository->getMaxBatchNumber($emptyCampaignId) === 0,
        'Empty campaign max batch number must be zero.',
    );
    $startedAt = new DateTimeImmutable('2026-02-03 10:15:30', new DateTimeZone('Asia/Jakarta'));
    $activeBatch = $batchRepository->create(
        $batchCampaignId,
        7,
        $versionOne,
        'Batch Seven',
        CampaignBatch::STATUS_ACTIVE,
        1,
        $startedAt,
        null,
    );
    campaignRepositoryAssert(
        $activeBatch->campaignId === $batchCampaignId
            && $activeBatch->batchNumber === 7
            && $activeBatch->quizVersionId === $versionOne
            && $activeBatch->quizVersionId !== $versionTwo
            && $activeBatch->startedAt === '2026-02-03 03:15:30',
        'Batch repository did not create the explicitly supplied batch snapshot.',
    );
    campaignRepositoryAssert(
        $batchRepository->findById($activeBatch->id)?->id === $activeBatch->id
            && $batchRepository->findById(999999) === null,
        'Batch repository find-by-id contract is incorrect.',
    );
    campaignRepositoryAssert(
        $batchRepository->findActiveByCampaignId($batchCampaignId)?->id === $activeBatch->id,
        'Active batch lookup did not use the active batch marker.',
    );
    expectCampaignRepositoryLogicException(
        static fn () => $batchRepository->findActiveByCampaignIdForUpdate($batchCampaignId),
        'Active batch lock lookup accepted a missing caller transaction.',
    );
    $connection->beginTransaction();
    try {
        campaignRepositoryAssert(
            $batchRepository->findActiveByCampaignIdForUpdate($batchCampaignId)?->id === $activeBatch->id,
            'Active batch lock lookup did not return the marked batch.',
        );
    } finally {
        $connection->rollBack();
    }

    expectCampaignRepositoryPdoException(
        static fn () => $batchRepository->create(
            $batchCampaignId,
            7,
            $versionOne,
            null,
            CampaignBatch::STATUS_CLOSED,
            null,
            $startedAt,
            $startedAt,
        ),
        'Duplicate batch number for one campaign was accepted.',
    );
    expectCampaignRepositoryPdoException(
        static fn () => $batchRepository->create(
            $batchCampaignId,
            8,
            $versionOne,
            null,
            CampaignBatch::STATUS_ACTIVE,
            1,
            $startedAt,
            null,
        ),
        'Second active marker for one campaign was accepted.',
    );
    expectCampaignRepositoryPdoException(
        static fn () => $batchRepository->create(
            999999,
            1,
            $versionOne,
            null,
            CampaignBatch::STATUS_CLOSED,
            null,
            $startedAt,
            $startedAt,
        ),
        'Invalid campaign foreign key was accepted.',
    );
    expectCampaignRepositoryPdoException(
        static fn () => $batchRepository->create(
            $batchCampaignId,
            8,
            999999,
            null,
            CampaignBatch::STATUS_CLOSED,
            null,
            $startedAt,
            $startedAt,
        ),
        'Invalid quiz version foreign key was accepted.',
    );

    $closedAt = new DateTimeImmutable('2026-02-03 11:15:30', new DateTimeZone('Asia/Jakarta'));
    campaignRepositoryAssert(
        $batchRepository->close($activeBatch->id, $closedAt),
        'Batch repository did not close the specified active batch.',
    );
    $closedBatch = $batchRepository->findById($activeBatch->id);
    campaignRepositoryAssert(
        $closedBatch !== null
            && $closedBatch->status === CampaignBatch::STATUS_CLOSED
            && $closedBatch->activeMarker === null
            && $closedBatch->closedAt === '2026-02-03 04:15:30'
            && $batchRepository->findActiveByCampaignId($batchCampaignId) === null
            && countCampaignBatches($connection, $batchCampaignId) === 1,
        'Closing a batch did not preserve history without creating a replacement.',
    );

    $closedBatchThree = $batchRepository->create(
        $batchCampaignId,
        3,
        $versionOne,
        null,
        CampaignBatch::STATUS_CLOSED,
        null,
        $startedAt,
        $closedAt,
    );
    $activeBatchNine = $batchRepository->create(
        $batchCampaignId,
        9,
        $versionOne,
        'Batch Nine',
        CampaignBatch::STATUS_ACTIVE,
        1,
        $startedAt,
        null,
    );
    $history = $batchRepository->listByCampaignId($batchCampaignId);
    campaignRepositoryAssert(
        array_map(static fn (CampaignBatch $batch): int => $batch->batchNumber, $history) === [3, 7, 9]
            && $closedBatchThree->activeMarker === null
            && $closedBatch->activeMarker === null
            && $batchRepository->findActiveByCampaignId($batchCampaignId)?->id === $activeBatchNine->id
            && $batchRepository->getMaxBatchNumber($batchCampaignId) === 9,
        'Batch history ordering, closed history, active lookup, or max batch number is incorrect.',
    );

    campaignRepositoryAssert(
        $batchRepository->close($activeBatchNine->id, $closedAt),
        'Specific active batch close failed.',
    );
    $connection->beginTransaction();
    try {
        $markerOnlyActiveBatch = $batchRepository->create(
            $batchCampaignId,
            10,
            $versionOne,
            null,
            CampaignBatch::STATUS_CLOSED,
            1,
            $startedAt,
            $closedAt,
        );
        campaignRepositoryAssert(
            $batchRepository->findActiveByCampaignId($batchCampaignId)?->id === $markerOnlyActiveBatch->id,
            'Active lookup must use active_marker rather than status alone.',
        );
    } finally {
        $connection->rollBack();
    }
    $connection->beginTransaction();
    try {
        $rolledBackBatch = $batchRepository->create(
            $batchCampaignId,
            11,
            $versionOne,
            null,
            CampaignBatch::STATUS_ACTIVE,
            1,
            $startedAt,
            null,
        );
        $rolledBackBatchId = $rolledBackBatch->id;
        $connection->rollBack();
    } catch (Throwable $throwable) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $throwable;
    }
    campaignRepositoryAssert(
        isset($rolledBackBatchId) && $batchRepository->findById($rolledBackBatchId) === null,
        'Repository create committed despite a caller rollback.',
    );
    campaignRepositoryAssert(
        !method_exists($batchRepository, 'deleteBatch') && !method_exists($batchRepository, 'deleteHistory'),
        'Campaign batch repository exposes a hard-delete API.',
    );

    echo "Campaign repository integration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    }
}
