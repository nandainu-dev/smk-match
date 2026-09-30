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
use App\Core\Request;
use App\Core\SmartLinkRepository;
use App\Core\SmartLinkService;

const SMART_LINK_HTTP_TEST_DATABASE = 'smk_match_g10_r6_test';

function smartLinkHttpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function smartLinkHttpMigrationStatements(string $path): array
{
    $contents = file_get_contents($path);
    smartLinkHttpAssert($contents !== false, 'Migration could not be read: ' . basename($path));

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

function createSmartLinkHttpVersion(
    PDO $connection,
    int $quizId,
    int $number,
    string $name,
    int $programId,
): int
{
    $statement = $connection->prepare(
        'INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES (:quiz_id, :version_number, :status, :name, UTC_TIMESTAMP())'
    );
    $statement->execute([
        'quiz_id' => $quizId,
        'version_number' => $number,
        'status' => 'published',
        'name' => $name,
    ]);
    $versionId = (int) $connection->lastInsertId();

    $connection->exec(
        "INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at)
         VALUES ({$versionId}, {$programId}, UTC_TIMESTAMP())"
    );
    $connection->exec(
        "INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at)
         VALUES ({$versionId}, 'HTTP entry question {$number}', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $questionId = (int) $connection->lastInsertId();
    $optionStatement = $connection->prepare(
        'INSERT INTO question_options (question_id, option_text, sort_order, created_at)
         VALUES (:question_id, :option_text, :sort_order, UTC_TIMESTAMP())'
    );
    $optionIds = [];

    foreach ([
        ['text' => 'HTTP entry option one', 'order' => 1],
        ['text' => 'HTTP entry option two', 'order' => 2],
    ] as $option) {
        $optionStatement->execute([
            'question_id' => $questionId,
            'option_text' => $option['text'],
            'sort_order' => $option['order'],
        ]);
        $optionIds[] = (int) $connection->lastInsertId();
    }

    $weightStatement = $connection->prepare(
        'INSERT INTO option_weights (question_option_id, program_id, weight, created_at)
         VALUES (:question_option_id, :program_id, :weight, UTC_TIMESTAMP())'
    );
    foreach ($optionIds as $optionId) {
        $weightStatement->execute([
            'question_option_id' => $optionId,
            'program_id' => $programId,
            'weight' => 1,
        ]);
    }

    return $versionId;
}

function createSmartLinkHttpCampaign(
    PDO $connection,
    int $schoolId,
    int $quizId,
    int $quizVersionId,
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

function expectSmartLinkHttpNotPlayable($router, string $path, string $message): void
{
    $response = $router->dispatch(new Request('GET', $path));

    smartLinkHttpAssert($response->status === 404, $message);
    smartLinkHttpAssert(
        !str_contains($response->body, 'Campaign siap'),
        'A non-playable alias rendered the successful campaign entry state.',
    );
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

smartLinkHttpAssert(
    $host === '127.0.0.1'
        && $port === '3306'
        && $databaseName === SMART_LINK_HTTP_TEST_DATABASE,
    'Unsafe test database configuration.',
);
smartLinkHttpAssert(
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
    $admin->exec('DROP DATABASE IF EXISTS ' . SMART_LINK_HTTP_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . SMART_LINK_HTTP_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $connection = $database->connection();
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migration) {
        foreach (smartLinkHttpMigrationStatements($migration) as $statement) {
            $connection->exec($statement);
        }
    }

    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('77777777-7777-4777-8777-777777777777', 'HTTP Entry School', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO programs (school_id, name, short_name, created_at, updated_at)
         VALUES ({$schoolId}, 'HTTP Entry Program', 'HTTP_ENTRY', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $programId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolId}, 'HTTP Entry Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizId = (int) $connection->lastInsertId();
    $versionOne = createSmartLinkHttpVersion(
        $connection,
        $quizId,
        1,
        'HTTP Entry Version One',
        $programId,
    );
    $versionTwo = createSmartLinkHttpVersion(
        $connection,
        $quizId,
        2,
        'HTTP Entry Version Two',
        $programId,
    );

    $campaignRepository = new CampaignRepository($database);
    $batchRepository = new CampaignBatchRepository($database);
    $smartLinkRepository = new SmartLinkRepository($database);
    $campaignService = new CampaignService(
        $database,
        $campaignRepository,
        $batchRepository,
        new QuizVersionRepository($database),
    );
    $smartLinkService = new SmartLinkService(
        $smartLinkRepository,
        $campaignRepository,
        $batchRepository,
    );

    $campaignId = createSmartLinkHttpCampaign(
        $connection,
        $schoolId,
        $quizId,
        $versionOne,
        'HTTP Entry Campaign',
    );
    $firstBatch = $campaignService->activateCampaign($campaignId);
    smartLinkHttpAssert(
        $campaignRepository->updateQuizVersionId($campaignId, $versionTwo),
        'Controlled fixture could not set a newer configured version after activation.',
    );
    $smartLinkService->create($schoolId, $campaignId, 'Public HTTP link', 'ppdb');
    $smartLinkService->create($schoolId, $campaignId, 'Inactive HTTP link', 'inactive-link', null, false);

    $draftCampaignId = createSmartLinkHttpCampaign(
        $connection,
        $schoolId,
        $quizId,
        $versionOne,
        'Draft HTTP Campaign',
    );
    $smartLinkService->create($schoolId, $draftCampaignId, 'Draft HTTP link', 'draft-link');

    $noBatchCampaignId = createSmartLinkHttpCampaign(
        $connection,
        $schoolId,
        $quizId,
        $versionOne,
        'No Batch HTTP Campaign',
    );
    smartLinkHttpAssert(
        $campaignRepository->updateStatus($noBatchCampaignId, 'draft', 'active'),
        'No-batch fixture could not be made active.',
    );
    $smartLinkService->create($schoolId, $noBatchCampaignId, 'No batch HTTP link', 'no-batch-link');

    $routes = require SMK_MATCH_ROOT . '/routes/web.php';
    $router = $routes($config);

    smartLinkHttpAssert($router->dispatch(new Request('GET', '/'))->status === 200, 'Root route changed.');
    smartLinkHttpAssert($router->dispatch(new Request('GET', '/health'))->status === 200, 'Health route changed.');
    foreach (['/result', '/result/dkv', '/result/mplb', '/result/pm', '/result/tie', '/result/error'] as $resultRoute) {
        smartLinkHttpAssert(
            $router->dispatch(new Request('GET', $resultRoute))->status === 200,
            'Result route changed: ' . $resultRoute,
        );
    }

    $firstResolution = $smartLinkService->resolve('ppdb');
    $response = $router->dispatch(new Request('GET', '/go/ppdb'));
    smartLinkHttpAssert($response->status === 200, 'Playable alias did not return HTTP 200.');
    smartLinkHttpAssert(str_contains($response->body, 'Campaign siap'), 'Playable alias did not render the safe entry state.');
    smartLinkHttpAssert(!str_contains($response->body, 'participant-quiz'), 'Playable alias invoked the G8 fixture flow.');
    smartLinkHttpAssert(!isset($response->headers['Location']), 'Playable alias returned a redirect.');
    smartLinkHttpAssert(
        ($response->headers['Cache-Control'] ?? null) === 'no-store, max-age=0',
        'Entry response must not cache a stale active batch.',
    );
    foreach (['campaign_id', 'batch_id', 'quiz_version_id', 'weights', 'answer', 'PDO', 'SQL'] as $forbiddenValue) {
        smartLinkHttpAssert(!str_contains($response->body, $forbiddenValue), 'Entry response exposes forbidden content: ' . $forbiddenValue);
    }
    smartLinkHttpAssert(
        $firstResolution->campaignId === $campaignId
            && $firstResolution->activeBatchId === $firstBatch->id
            && $firstResolution->quizVersionId === $versionOne
            && $firstResolution->quizVersionId !== $versionTwo,
        'HTTP entry did not use the first active-batch snapshot.',
    );

    expectSmartLinkHttpNotPlayable($router, '/go/unknown-link', 'Unknown alias was playable.');
    expectSmartLinkHttpNotPlayable($router, '/go/admin', 'Reserved alias was playable.');
    expectSmartLinkHttpNotPlayable($router, '/go/inactive-link', 'Inactive smart link was playable.');
    expectSmartLinkHttpNotPlayable($router, '/go/draft-link', 'Draft campaign was playable.');
    expectSmartLinkHttpNotPlayable($router, '/go/no-batch-link', 'Active campaign without an active batch was playable.');
    expectSmartLinkHttpNotPlayable($router, '/go/javascript:alert(1)', 'Invalid alias was playable.');
    expectSmartLinkHttpNotPlayable($router, '/go/https:%2F%2Fevil.example', 'External-looking alias was playable.');

    $secondBatch = $campaignService->resetActiveBatch($campaignId);
    $secondResolution = $smartLinkService->resolve('ppdb');
    $historicalFirstBatch = $batchRepository->findById($firstBatch->id);
    $afterReset = $router->dispatch(new Request('GET', '/go/ppdb'));
    smartLinkHttpAssert($afterReset->status === 200, 'Alias stopped working after reset.');
    smartLinkHttpAssert(!isset($afterReset->headers['Location']), 'Reset route response returned a redirect.');
    smartLinkHttpAssert(
        $secondResolution->alias === 'ppdb'
            && $secondResolution->campaignId === $campaignId
            && $secondResolution->activeBatchId === $secondBatch->id
            && $secondResolution->activeBatchId !== $firstBatch->id
            && $secondResolution->quizVersionId === $versionTwo
            && $historicalFirstBatch !== null
            && $historicalFirstBatch->status === CampaignBatch::STATUS_CLOSED
            && $historicalFirstBatch->quizVersionId === $versionOne,
        'Alias did not retain stable reset semantics.',
    );

    $scanCount = $connection->query("SELECT scan_count FROM smart_links WHERE alias = 'ppdb'")->fetchColumn();
    smartLinkHttpAssert((int) $scanCount === 0, 'HTTP entry mutated scan_count before analytics is designed.');

    $controllerSource = (string) file_get_contents(SMK_MATCH_ROOT . '/app/Controllers/PublicCampaignEntryController.php');
    smartLinkHttpAssert(!str_contains($controllerSource, 'Repository'), 'Controller directly references a persistence repository.');
    smartLinkHttpAssert(!str_contains($controllerSource, 'SELECT '), 'Controller directly queries the database.');

    echo "Smart link HTTP integration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . SMART_LINK_HTTP_TEST_DATABASE);
    }
}
