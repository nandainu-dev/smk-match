<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\CampaignService;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\QuizVersionRepository;
use App\Core\SmartLinkRepository;
use App\Core\SmartLinkService;

const PUBLIC_START_HTTP_TEST_DATABASE = 'smk_match_g11_r7_test';

function publicStartAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function publicStartApplyMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $sql = file_get_contents($path);
        publicStartAssert($sql !== false, 'Migration could not be read.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @return array{id: int, questions: array<int, array{id: int, options: array<int, int>}>} */
function publicStartVersion(PDO $connection, int $quizId, int $programId, int $number, string $status): array
{
    $connection->prepare('INSERT INTO quiz_versions (quiz_id, version_number, status, name, published_at, created_at) VALUES (:quiz_id, :number, :status, :name, :published_at, UTC_TIMESTAMP())')->execute([
        'quiz_id' => $quizId, 'number' => $number, 'status' => $status, 'name' => 'HTTP Start Version ' . $number, 'published_at' => $status === 'published' ? '2026-03-12 00:00:00' : null,
    ]);
    $versionId = (int) $connection->lastInsertId();
    $connection->prepare('INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at) VALUES (:version_id, :program_id, UTC_TIMESTAMP())')->execute(['version_id' => $versionId, 'program_id' => $programId]);
    $membershipId = (int) $connection->lastInsertId();
    $connection->prepare("INSERT INTO quiz_version_program_presentations (quiz_version_program_id, program_code_snapshot, program_name_snapshot, personality_title_snapshot, mascot_path_snapshot, description_snapshot, skills_snapshot, snapshot_provenance, created_at, updated_at) SELECT :membership_id, short_name, name, personality_title, mascot_path, description, skills_json, 'version_snapshot', UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM programs WHERE id = :program_id")->execute(['membership_id' => $membershipId, 'program_id' => $programId]);
    $questions = [];
    foreach ([1, 2] as $order) {
        $connection->prepare('INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at) VALUES (:version_id, :prompt, :order, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['version_id' => $versionId, 'prompt' => 'HTTP Question ' . $order, 'order' => $order]);
        $questionId = (int) $connection->lastInsertId();
        $options = [];
        foreach ([1, 2] as $optionOrder) {
            $connection->prepare('INSERT INTO question_options (question_id, option_text, sort_order, created_at) VALUES (:question_id, :text, :order, UTC_TIMESTAMP())')->execute(['question_id' => $questionId, 'text' => 'HTTP Option ' . $optionOrder, 'order' => $optionOrder]);
            $optionId = (int) $connection->lastInsertId();
            $options[$optionOrder] = $optionId;
            $connection->prepare('INSERT INTO option_weights (question_option_id, program_id, weight, created_at) VALUES (:option_id, :program_id, :weight, UTC_TIMESTAMP())')->execute(['option_id' => $optionId, 'program_id' => $programId, 'weight' => (float) $optionOrder]);
        }
        $questions[$order] = ['id' => $questionId, 'options' => $options];
    }

    return ['id' => $versionId, 'questions' => $questions];
}

function publicStartBatch(PDO $connection, int $campaignId, int $versionId, int $number): int
{
    $connection->prepare('INSERT INTO campaign_batches (campaign_id, batch_number, quiz_version_id, label, status, active_marker, started_at, created_at, updated_at) VALUES (:campaign_id, :number, :version_id, :label, :status, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['campaign_id' => $campaignId, 'number' => $number, 'version_id' => $versionId, 'label' => 'HTTP Batch ' . $number, 'status' => 'active']);

    return (int) $connection->lastInsertId();
}

/** @param array<string, mixed> $payload @param array<string, string> $cookies */
function publicStartRequest(string $alias, string $key, array $payload, array $cookies = [], bool $https = false): Request
{
    return new Request(
        'POST',
        '/api/public/start/' . $alias,
        ['Content-Type' => 'application/json; charset=utf-8', 'Idempotency-Key' => $key],
        json_encode($payload, JSON_THROW_ON_ERROR),
        $cookies,
        $https,
    );
}

function publicStartCookieUuid(string $header): string
{
    preg_match('/^smk_match_visitor=([^;]+)/', $header, $matches);
    return $matches[1] ?? '';
}

function publicStartCount(PDO $connection, string $table): int
{
    return (int) $connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
publicStartAssert($host === '127.0.0.1' && $port === '3306' && $databaseName === PUBLIC_START_HTTP_TEST_DATABASE, 'Unsafe test database configuration.');
publicStartAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $admin->exec('DROP DATABASE IF EXISTS ' . PUBLIC_START_HTTP_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . PUBLIC_START_HTTP_TEST_DATABASE . ' CHARACTER SET utf8mb4');
    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $connection = $database->connection();
    publicStartApplyMigrations($connection);
    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('81000000-0000-4000-8000-000000000001', 'HTTP Start School', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO programs (school_id, name, short_name, created_at, updated_at) VALUES ({$schoolId}, 'HTTP Program', 'HTTP', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $programId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES ({$schoolId}, 'HTTP Start Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $quizId = (int) $connection->lastInsertId();
    $versionOne = publicStartVersion($connection, $quizId, $programId, 1, 'published');
    $versionTwo = publicStartVersion($connection, $quizId, $programId, 2, 'published');
    $draftVersion = publicStartVersion($connection, $quizId, $programId, 3, 'draft');
    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'quiz_id' => $quizId, 'version_id' => $versionOne['id'], 'name' => 'HTTP Campaign', 'status' => 'active']);
    $campaignId = (int) $connection->lastInsertId();
    $batchOneId = publicStartBatch($connection, $campaignId, $versionOne['id'], 1);
    $campaignRepository = new CampaignRepository($database);
    $batchRepository = new CampaignBatchRepository($database);
    $smartLinks = new SmartLinkService(new SmartLinkRepository($database), $campaignRepository, $batchRepository);
    $smartLinks->create($schoolId, $campaignId, 'HTTP Start Link', 'http-start');
    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'quiz_id' => $quizId, 'version_id' => $versionOne['id'], 'name' => 'Second HTTP Campaign', 'status' => 'active']);
    $secondCampaignId = (int) $connection->lastInsertId();
    publicStartBatch($connection, $secondCampaignId, $versionOne['id'], 1);
    $smartLinks->create($schoolId, $secondCampaignId, 'Second HTTP Start Link', 'http-start-two');
    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'quiz_id' => $quizId, 'version_id' => $versionOne['id'], 'name' => 'Inactive HTTP Campaign', 'status' => 'draft']);
    $inactiveCampaignId = (int) $connection->lastInsertId();
    $smartLinks->create($schoolId, $inactiveCampaignId, 'Inactive HTTP Start Link', 'http-inactive');
    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'quiz_id' => $quizId, 'version_id' => $versionOne['id'], 'name' => 'No Batch HTTP Campaign', 'status' => 'active']);
    $noBatchCampaignId = (int) $connection->lastInsertId();
    $smartLinks->create($schoolId, $noBatchCampaignId, 'No Batch HTTP Start Link', 'http-no-batch');
    $routes = require SMK_MATCH_ROOT . '/routes/web.php';
    $router = $routes($config);
    $payload = ['full_name' => 'HTTP Participant', 'origin_school' => 'Other School', 'class_name' => 'Class 10', 'phone' => '081200000001', 'marketing_consent' => false];
    $keyOne = '84000000-0000-4000-8000-000000000001';
    $response = $router->dispatch(publicStartRequest('http-start', $keyOne, $payload));
    $json = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    $cookie = (string) ($response->headers['Set-Cookie'] ?? '');
    $visitorUuid = publicStartCookieUuid($cookie);
    publicStartAssert($response->status === 201 && $json === ['ok' => true, 'attempt_uuid' => $keyOne, 'status' => 'started', 'already_started' => false], 'Valid new HTTP start response is invalid.');
    publicStartAssert(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $visitorUuid) === 1 && !str_contains($cookie, 'HTTP Participant') && str_contains($cookie, 'HttpOnly') && str_contains($cookie, 'SameSite=Lax') && str_contains($cookie, 'Path=/') && str_contains($cookie, 'Max-Age=31536000') && !str_contains($cookie, 'Secure'), 'Visitor cookie is not opaque or lacks local-safe attributes.');
    publicStartAssert(publicStartCount($connection, 'participants') === 1 && publicStartCount($connection, 'attempts') === 1 && (int) $connection->query("SELECT campaign_batch_id FROM attempts WHERE attempt_uuid = '{$keyOne}'")->fetchColumn() === $batchOneId && (int) $connection->query("SELECT quiz_version_id FROM attempts WHERE attempt_uuid = '{$keyOne}'")->fetchColumn() === $versionOne['id'], 'Valid HTTP start did not persist exact active batch bindings.');

    $retryPayload = ['full_name' => 'Changed', 'origin_school' => null, 'class_name' => 'Changed', 'phone' => '089999999999', 'marketing_consent' => true];
    $retry = $router->dispatch(publicStartRequest('http-start', $keyOne, $retryPayload, ['smk_match_visitor' => $visitorUuid]));
    $retryJson = json_decode($retry->body, true, 512, JSON_THROW_ON_ERROR);
    publicStartAssert($retry->status === 200 && $retryJson['already_started'] === true && publicStartCount($connection, 'participants') === 1 && publicStartCount($connection, 'attempts') === 1 && (string) $connection->query('SELECT full_name FROM participants LIMIT 1')->fetchColumn() === 'HTTP Participant', 'HTTP idempotent retry mutated identity or duplicated rows.');
    $visitorConflict = $router->dispatch(publicStartRequest('http-start', $keyOne, $payload, ['smk_match_visitor' => '85000000-0000-4000-8000-000000000001']));
    publicStartAssert($visitorConflict->status === 409 && json_decode($visitorConflict->body, true)['error'] === 'idempotency_conflict', 'HTTP visitor conflict is not safe 409.');
    $campaignConflict = $router->dispatch(publicStartRequest('http-start-two', $keyOne, $payload, ['smk_match_visitor' => $visitorUuid]));
    publicStartAssert($campaignConflict->status === 409, 'HTTP campaign conflict is not safe 409.');

    $keyTwo = '84000000-0000-4000-8000-000000000002';
    $existingVisitor = '85000000-0000-4000-8000-000000000002';
    $existing = $router->dispatch(publicStartRequest('http-start', $keyTwo, $payload, ['smk_match_visitor' => $existingVisitor]));
    publicStartAssert($existing->status === 201 && (string) $connection->query("SELECT visitor_uuid FROM attempts WHERE attempt_uuid = '{$keyTwo}'")->fetchColumn() === $existingVisitor, 'Existing valid visitor cookie was not persisted.');
    $malformed = $router->dispatch(publicStartRequest('http-start', '84000000-0000-4000-8000-000000000003', $payload, ['smk_match_visitor' => 'malformed']));
    $replacementVisitor = publicStartCookieUuid((string) $malformed->headers['Set-Cookie']);
    publicStartAssert($malformed->status === 201 && $replacementVisitor !== 'malformed' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $replacementVisitor) === 1, 'Malformed visitor cookie was not rotated.');
    $httpsStart = $router->dispatch(publicStartRequest('http-start', '84000000-0000-4000-8000-000000000010', $payload, [], true));
    publicStartAssert($httpsStart->status === 201 && str_contains((string) $httpsStart->headers['Set-Cookie'], 'Secure'), 'Known HTTPS request did not receive a Secure visitor cookie.');

    $beforeInvalid = [publicStartCount($connection, 'participants'), publicStartCount($connection, 'attempts')];
    $missingKey = $router->dispatch(new Request('POST', '/api/public/start/http-start', ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)));
    $badKey = $router->dispatch(publicStartRequest('http-start', 'not-a-uuid', $payload));
    $badJson = $router->dispatch(new Request('POST', '/api/public/start/http-start', ['Content-Type' => 'application/json', 'Idempotency-Key' => '84000000-0000-4000-8000-000000000004'], '{bad'));
    $wrongType = $router->dispatch(new Request('POST', '/api/public/start/http-start', ['Content-Type' => 'text/plain', 'Idempotency-Key' => '84000000-0000-4000-8000-000000000005'], json_encode($payload, JSON_THROW_ON_ERROR)));
    $wrongField = $router->dispatch(publicStartRequest('http-start', '84000000-0000-4000-8000-000000000006', ['full_name' => [], 'marketing_consent' => 'false']));
    $arrayRoot = $router->dispatch(new Request('POST', '/api/public/start/http-start', ['Content-Type' => 'application/json', 'Idempotency-Key' => '84000000-0000-4000-8000-000000000013'], '[]'));
    $unknown = $router->dispatch(publicStartRequest('unknown-alias', '84000000-0000-4000-8000-000000000007', $payload));
    $inactive = $router->dispatch(publicStartRequest('http-inactive', '84000000-0000-4000-8000-000000000011', $payload));
    $noBatch = $router->dispatch(publicStartRequest('http-no-batch', '84000000-0000-4000-8000-000000000012', $payload));
    publicStartAssert($missingKey->status === 400 && $badKey->status === 422 && $badJson->status === 400 && $wrongType->status === 415 && $wrongField->status === 422 && $arrayRoot->status === 422 && $unknown->status === 404 && $inactive->status === 404 && $noBatch->status === 404 && $beforeInvalid === [publicStartCount($connection, 'participants'), publicStartCount($connection, 'attempts')], 'Invalid public start requests mutated persistence or returned unsafe statuses.');

    publicStartAssert($router->dispatch(new Request('GET', '/api/public/start/http-start'))->status === 405 && $beforeInvalid === [publicStartCount($connection, 'participants'), publicStartCount($connection, 'attempts')], 'GET start endpoint mutated data or was not rejected.');
    $campaignRepository->updateQuizVersionId($campaignId, $versionTwo['id']);
    $beforeReset = $router->dispatch(publicStartRequest('http-start', '84000000-0000-4000-8000-000000000008', $payload));
    $batchTwo = (new CampaignService($database, $campaignRepository, $batchRepository, new QuizVersionRepository($database)))->resetActiveBatch($campaignId);
    $afterReset = $router->dispatch(publicStartRequest('http-start', '84000000-0000-4000-8000-000000000009', $payload));
    publicStartAssert($beforeReset->status === 201 && $afterReset->status === 201 && (int) $connection->query("SELECT campaign_batch_id FROM attempts WHERE attempt_uuid = '84000000-0000-4000-8000-000000000008'")->fetchColumn() === $batchOneId && (int) $connection->query("SELECT campaign_batch_id FROM attempts WHERE attempt_uuid = '84000000-0000-4000-8000-000000000009'")->fetchColumn() === $batchTwo->id, 'HTTP starts did not honor reset batch transition.');
    publicStartAssert($router->dispatch(new Request('GET', '/'))->status === 200 && $router->dispatch(new Request('GET', '/health'))->status === 200 && $router->dispatch(new Request('GET', '/go/http-start'))->status === 200 && $router->dispatch(new Request('GET', '/result'))->status === 200 && $router->dispatch(new Request('GET', '/result/dkv'))->status === 200 && $router->dispatch(new Request('GET', '/result/mplb'))->status === 200 && $router->dispatch(new Request('GET', '/result/pm'))->status === 200 && $router->dispatch(new Request('GET', '/result/tie'))->status === 200 && $router->dispatch(new Request('GET', '/result/error'))->status === 200, 'Existing route regression failed.');
    $successBody = $response->body;
    foreach (['full_name', 'phone', 'origin_school', 'class_name', 'participant', 'weights', 'raw_score', 'percentage', 'question'] as $forbidden) {
        publicStartAssert(!str_contains($successBody, $forbidden), 'Success response exposes private or quiz content.');
    }
    publicStartAssert(publicStartCount($connection, 'responses') === 0 && publicStartCount($connection, 'results') === 0 && publicStartCount($connection, 'result_scores') === 0 && publicStartCount($connection, 'result_tied_programs') === 0, 'HTTP start created responses or results.');

    echo "Public participant start HTTP tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . PUBLIC_START_HTTP_TEST_DATABASE);
    }
}
