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
use App\Core\SmartLinkRepository;
use App\Core\SmartLinkService;

const TEST_DATABASE = 'smk_match_g10_r5_test';

function smartLinkAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectSmartLinkInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function expectSmartLinkRuntime(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

function expectSmartLinkPdoException(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @return list<string> */
function smartLinkStatements(string $path): array
{
    $contents = file_get_contents($path);
    smartLinkAssert($contents !== false, 'Migration could not be read: ' . basename($path));

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

function createSmartLinkCampaign(
    PDO $connection,
    int $schoolId,
    int $quizId,
    int $quizVersionId,
    string $name,
): int {
    $statement = $connection->prepare(
        'INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at)
         VALUES (:school_id, :quiz_id, :quiz_version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
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

function createSmartLinkVersion(
    PDO $connection,
    int $quizId,
    int $versionNumber,
    string $name,
    int $programId,
): int {
    $versionStatement = $connection->prepare(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES (:quiz_id, :version_number, 'published', :name, UTC_TIMESTAMP())"
    );
    $versionStatement->execute([
        'quiz_id' => $quizId,
        'version_number' => $versionNumber,
        'name' => $name,
    ]);
    $versionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at)
         VALUES ({$versionId}, {$programId}, UTC_TIMESTAMP())"
    );
    $membershipId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO quiz_version_program_presentations (quiz_version_program_id, program_code_snapshot, program_name_snapshot, personality_title_snapshot, mascot_path_snapshot, description_snapshot, skills_snapshot, snapshot_provenance, created_at, updated_at) SELECT {$membershipId}, short_name, name, personality_title, mascot_path, description, skills_json, 'version_snapshot', UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM programs WHERE id = {$programId}");
    $connection->exec(
        "INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at)
         VALUES ({$versionId}, 'Smart link question {$versionId}', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $questionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO question_options (question_id, option_text, sort_order, created_at)
         VALUES
            ({$questionId}, 'Smart link option one', 1, UTC_TIMESTAMP()),
            ({$questionId}, 'Smart link option two', 2, UTC_TIMESTAMP())"
    );
    $optionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO option_weights (question_option_id, program_id, weight, created_at)
         VALUES ({$optionId}, {$programId}, 1, UTC_TIMESTAMP())"
    );

    return $versionId;
}

function countSmartLinkBatches(PDO $connection, int $campaignId): int
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

smartLinkAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === TEST_DATABASE,
    'Unsafe test database configuration.',
);
smartLinkAssert(
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
        foreach (smartLinkStatements($migration) as $statement) {
            $connection->exec($statement);
        }
    }

    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('77777777-7777-4777-8777-777777777777', 'Smart Link School A', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolA = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('88888888-8888-4888-8888-888888888888', 'Smart Link School B', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolB = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO programs (school_id, name, short_name, created_at, updated_at)
         VALUES ({$schoolA}, 'Smart Link Program', 'SMART', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $programId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolA}, 'Smart Link Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizId = (int) $connection->lastInsertId();
    $versionOne = createSmartLinkVersion($connection, $quizId, 1, 'Smart Link Version One', $programId);
    $versionTwo = createSmartLinkVersion($connection, $quizId, 2, 'Smart Link Version Two', $programId);

    $campaignRepository = new CampaignRepository($database);
    $batchRepository = new CampaignBatchRepository($database);
    $campaignService = new CampaignService(
        $database,
        $campaignRepository,
        $batchRepository,
        new QuizVersionRepository($database),
    );
    $smartLinkRepository = new SmartLinkRepository($database);
    $smartLinkService = new SmartLinkService(
        $smartLinkRepository,
        $campaignRepository,
        $batchRepository,
    );
    $activationTime = new DateTimeImmutable('2026-04-01 10:00:00', new DateTimeZone('Asia/Jakarta'));
    $resetTime = new DateTimeImmutable('2026-04-01 11:00:00', new DateTimeZone('Asia/Jakarta'));

    $campaignA = createSmartLinkCampaign($connection, $schoolA, $quizId, $versionOne, 'Smart alias campaign');
    $firstBatch = $campaignService->activateCampaign($campaignA, $activationTime);
    smartLinkAssert(
        $campaignRepository->updateQuizVersionId($campaignA, $versionTwo),
        'Controlled active campaign fixture could not retain a distinct configured version.',
    );
    $smartLink = $smartLinkService->create(
        $schoolA,
        $campaignA,
        'PPDB QR',
        ' ppdb ',
        'qr',
    );
    smartLinkAssert(
        $smartLink->alias === 'ppdb'
            && $smartLinkRepository->findById($smartLink->id)?->id === $smartLink->id
            && $smartLinkRepository->findByAlias('ppdb')?->id === $smartLink->id
            && $smartLinkRepository->findById(999999) === null
            && $smartLinkRepository->findByAlias('ppdb-next') === null,
        'Smart link repository did not provide exact deterministic lookup.',
    );
    expectSmartLinkPdoException(
        static fn () => $smartLinkService->create($schoolA, $campaignA, 'Duplicate PPDB', 'ppdb'),
        'Duplicate global smart alias was accepted.',
    );
    expectSmartLinkRuntime(
        static fn () => $smartLinkService->resolve('missing-alias'),
        'Unknown smart alias did not return not-found behavior.',
    );

    foreach (['PPDB', 'Open House', 'open_house', '/open-house', 'go/open-house', 'https://example.com', '../../admin', '%2Fadmin', 'a', 'ab', '//evil.example', 'javascript:alert(1)', 'ppdb?next=https://evil.example', 'ppdb#x'] as $invalidAlias) {
        expectSmartLinkInvalid(
            static fn () => $smartLinkService->create($schoolA, $campaignA, 'Invalid alias', $invalidAlias),
            'Invalid smart alias was accepted: ' . $invalidAlias,
        );
    }
    foreach (SmartLinkService::reservedAliases() as $reservedAlias) {
        expectSmartLinkInvalid(
            static fn () => $smartLinkService->create($schoolA, $campaignA, 'Reserved alias', $reservedAlias),
            'Reserved smart alias was accepted: ' . $reservedAlias,
        );
    }

    $firstResolution = $smartLinkService->resolve('ppdb');
    smartLinkAssert(
        $firstResolution->alias === 'ppdb'
            && $firstResolution->campaignId === $campaignA
            && $firstResolution->activeBatchId === $firstBatch->id
            && $firstResolution->quizVersionId === $versionOne
            && $firstResolution->quizVersionId !== $versionTwo,
        'Smart alias resolution did not use the exact active batch snapshot.',
    );

    $smartLinkService->create($schoolA, $campaignA, 'Disabled campaign link', 'disabled-link', null, false);
    expectSmartLinkRuntime(
        static fn () => $smartLinkService->resolve('disabled-link'),
        'Inactive smart alias was playable.',
    );

    $draftCampaign = createSmartLinkCampaign($connection, $schoolA, $quizId, $versionOne, 'Draft alias campaign');
    $smartLinkService->create($schoolA, $draftCampaign, 'Draft campaign link', 'draft-link');
    expectSmartLinkRuntime(
        static fn () => $smartLinkService->resolve('draft-link'),
        'Draft campaign smart alias was playable.',
    );

    $noBatchCampaign = createSmartLinkCampaign($connection, $schoolA, $quizId, $versionOne, 'No batch alias campaign');
    smartLinkAssert(
        $campaignRepository->updateStatus($noBatchCampaign, 'draft', 'active'),
        'No-batch campaign fixture could not be made active.',
    );
    $smartLinkService->create($schoolA, $noBatchCampaign, 'No batch link', 'no-batch-link');
    expectSmartLinkRuntime(
        static fn () => $smartLinkService->resolve('no-batch-link'),
        'Active campaign without an active batch was playable.',
    );

    expectSmartLinkRuntime(
        static fn () => $smartLinkService->create($schoolB, $campaignA, 'Cross school create', 'cross-create'),
        'Cross-school smart link creation was accepted.',
    );
    $crossSchoolLink = $smartLinkRepository->create($schoolB, $campaignA, 'Cross school persisted fixture', 'cross-school', null, true);
    expectSmartLinkRuntime(
        static fn () => $smartLinkService->resolve($crossSchoolLink->alias),
        'Cross-school smart link resolution was accepted.',
    );

    $secondBatch = $campaignService->resetActiveBatch($campaignA, $resetTime);
    $secondResolution = $smartLinkService->resolve('ppdb');
    $historicalFirstBatch = $batchRepository->findById($firstBatch->id);
    smartLinkAssert(
        $secondResolution->alias === 'ppdb'
            && $secondResolution->campaignId === $campaignA
            && $secondResolution->activeBatchId === $secondBatch->id
            && $secondResolution->quizVersionId === $versionTwo
            && $secondBatch->batchNumber === 2
            && $historicalFirstBatch !== null
            && $historicalFirstBatch->quizVersionId === $versionOne
            && $historicalFirstBatch->status === CampaignBatch::STATUS_CLOSED
            && countSmartLinkBatches($connection, $campaignA) === 2,
        'Alias did not remain stable across the approved active batch reset.',
    );

    echo "Smart link integration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    }
}
