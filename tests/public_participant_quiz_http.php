<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\AttemptRepository;
use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\CampaignService;
use App\Core\Config;
use App\Core\Database;
use App\Core\ParticipantRepository;
use App\Core\ParticipantStartService;
use App\Core\QuizVersionRepository;
use App\Core\Request;
use App\Core\SmartLinkRepository;
use App\Core\SmartLinkService;
use App\Core\UuidV4Generator;

const PUBLIC_QUIZ_HTTP_TEST_DATABASE = 'smk_match_g11_r8_test';

function publicQuizAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function publicQuizMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $sql = file_get_contents($path);
        publicQuizAssert($sql !== false, 'Migration could not be read.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @param array<string, int> $programIds */
function publicQuizVersion(PDO $connection, int $quizId, array $programIds, int $number, string $questionText): int
{
    $connection->prepare(
        'INSERT INTO quiz_versions (quiz_id, version_number, status, name, published_at, created_at)
         VALUES (:quiz_id, :number, :status, :name, :published_at, UTC_TIMESTAMP())'
    )->execute([
        'quiz_id' => $quizId,
        'number' => $number,
        'status' => 'published',
        'name' => 'Delivery Version ' . $number,
        'published_at' => '2026-09-30 00:00:00',
    ]);
    $versionId = (int) $connection->lastInsertId();
    $membership = $connection->prepare(
        'INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at)
         VALUES (:version_id, :program_id, UTC_TIMESTAMP())'
    );
    $presentation = $connection->prepare("INSERT INTO quiz_version_program_presentations (quiz_version_program_id, program_code_snapshot, program_name_snapshot, personality_title_snapshot, mascot_path_snapshot, description_snapshot, skills_snapshot, snapshot_provenance, created_at, updated_at) SELECT :membership_id, short_name, name, personality_title, mascot_path, description, skills_json, 'version_snapshot', UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM programs WHERE id = :program_id");
    foreach ($programIds as $programId) {
        $membership->execute(['version_id' => $versionId, 'program_id' => $programId]);
        $presentation->execute(['membership_id' => (int) $connection->lastInsertId(), 'program_id' => $programId]);
    }

    $question = $connection->prepare(
        'INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at)
         VALUES (:version_id, :prompt, :order, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $option = $connection->prepare(
        'INSERT INTO question_options (question_id, option_text, sort_order, created_at)
         VALUES (:question_id, :text, :order, UTC_TIMESTAMP())'
    );
    $weight = $connection->prepare(
        'INSERT INTO option_weights (question_option_id, program_id, weight, created_at)
         VALUES (:option_id, :program_id, :weight, UTC_TIMESTAMP())'
    );

    foreach ([[20, $questionText . ' later'], [10, $questionText . ' earlier']] as [$order, $prompt]) {
        $question->execute(['version_id' => $versionId, 'prompt' => $prompt, 'order' => $order]);
        $questionId = (int) $connection->lastInsertId();
        foreach ([[30, 'Choose the later option'], [10, 'Choose the earlier option']] as [$optionOrder, $text]) {
            $option->execute(['question_id' => $questionId, 'text' => $text, 'order' => $optionOrder]);
            $optionId = (int) $connection->lastInsertId();
            foreach ($programIds as $programId) {
                $weight->execute(['option_id' => $optionId, 'program_id' => $programId, 'weight' => 1.25]);
            }
        }
    }

    return $versionId;
}

function publicQuizBatch(PDO $connection, int $campaignId, int $versionId, int $number): int
{
    $connection->prepare(
        'INSERT INTO campaign_batches (campaign_id, batch_number, quiz_version_id, label, status, active_marker, started_at, created_at, updated_at)
         VALUES (:campaign_id, :number, :version_id, :label, :status, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'campaign_id' => $campaignId,
        'number' => $number,
        'version_id' => $versionId,
        'label' => 'Delivery Batch ' . $number,
        'status' => 'active',
    ]);

    return (int) $connection->lastInsertId();
}

function publicQuizStartService(Database $database): ParticipantStartService
{
    return new ParticipantStartService(
        $database,
        new AttemptRepository($database),
        new ParticipantRepository($database),
        new CampaignRepository($database),
        new CampaignBatchRepository($database),
        new QuizVersionRepository($database),
        new UuidV4Generator(),
    );
}

/** @return array<string, int> */
function publicQuizCounts(PDO $connection): array
{
    $counts = [];
    foreach (['participants', 'attempts', 'responses', 'results', 'result_scores', 'result_tied_programs', 'campaigns', 'campaign_batches', 'smart_links'] as $table) {
        $counts[$table] = (int) $connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    return $counts;
}

function publicQuizRequest(string $attemptUuid, ?string $visitorUuid): Request
{
    return new Request(
        'GET',
        '/api/public/quiz/' . $attemptUuid,
        [],
        '',
        $visitorUuid === null ? [] : ['smk_match_visitor' => $visitorUuid],
    );
}

function publicQuizCookieUuid(string $header): string
{
    preg_match('/^smk_match_visitor=([^;]+)/', $header, $matches);

    return $matches[1] ?? '';
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
publicQuizAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === PUBLIC_QUIZ_HTTP_TEST_DATABASE,
    'Unsafe test database configuration.',
);
publicQuizAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $admin->exec('DROP DATABASE IF EXISTS ' . PUBLIC_QUIZ_HTTP_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . PUBLIC_QUIZ_HTTP_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $connection = $database->connection();
    publicQuizMigrations($connection);
    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('91000000-0000-4000-8000-000000000001', 'Delivery School', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolId = (int) $connection->lastInsertId();
    $programIds = [];
    $program = $connection->prepare('INSERT INTO programs (school_id, name, short_name, created_at, updated_at) VALUES (:school_id, :name, :code, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    foreach (['ALPHA', 'BETA', 'GAMMA'] as $code) {
        $program->execute(['school_id' => $schoolId, 'name' => 'Program ' . $code, 'code' => $code]);
        $programIds[$code] = (int) $connection->lastInsertId();
    }
    $connection->exec("INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES ({$schoolId}, 'Delivery Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $quizId = (int) $connection->lastInsertId();
    $versionOneId = publicQuizVersion($connection, $quizId, $programIds, 1, 'Historical question V1');
    $versionTwoId = publicQuizVersion($connection, $quizId, $programIds, 2, 'Historical question V2');
    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'quiz_id' => $quizId, 'version_id' => $versionOneId, 'name' => 'Delivery Campaign', 'status' => 'active']);
    $campaignId = (int) $connection->lastInsertId();
    $batchOneId = publicQuizBatch($connection, $campaignId, $versionOneId, 1);
    $smartLinks = new SmartLinkService(
        new SmartLinkRepository($database),
        new CampaignRepository($database),
        new CampaignBatchRepository($database),
    );
    $smartLinks->create($schoolId, $campaignId, 'Delivery Entry Link', 'delivery');

    $start = publicQuizStartService($database);
    $oldVisitor = '92000000-0000-4000-8000-000000000001';
    $oldAttemptUuid = '93000000-0000-4000-8000-000000000001';
    $oldAttempt = $start->start($campaignId, $oldVisitor, $oldAttemptUuid, 'Old Participant', null, null, null, false, new DateTimeImmutable('2026-09-30 01:00:00', new DateTimeZone('UTC')))->attempt;
    publicQuizAssert($oldAttempt->campaignBatchId === $batchOneId && $oldAttempt->quizVersionId === $versionOneId, 'Initial attempt did not bind to version one.');

    $connection->prepare('UPDATE campaigns SET quiz_version_id = :version_id WHERE id = :id')->execute(['version_id' => $versionTwoId, 'id' => $campaignId]);
    $reset = new CampaignService($database, new CampaignRepository($database), new CampaignBatchRepository($database), new QuizVersionRepository($database));
    $batchTwo = $reset->resetActiveBatch($campaignId, new DateTimeImmutable('2026-09-30 02:00:00', new DateTimeZone('UTC')));
    publicQuizAssert($batchTwo->quizVersionId === $versionTwoId, 'Reset did not create a version two batch.');
    $newVisitor = '92000000-0000-4000-8000-000000000002';
    $newAttemptUuid = '93000000-0000-4000-8000-000000000002';
    $newAttempt = $start->start($campaignId, $newVisitor, $newAttemptUuid, 'New Participant', null, null, null, false, new DateTimeImmutable('2026-09-30 03:00:00', new DateTimeZone('UTC')))->attempt;

    $routes = require SMK_MATCH_ROOT . '/routes/web.php';
    $router = $routes($config);
    $beforeDelivery = publicQuizCounts($connection);
    $oldResponse = $router->dispatch(publicQuizRequest($oldAttemptUuid, $oldVisitor));
    $oldJson = json_decode($oldResponse->body, true, 512, JSON_THROW_ON_ERROR);
    publicQuizAssert($oldResponse->status === 200 && array_keys($oldJson) === ['ok', 'attempt_uuid', 'status', 'quiz'], 'Valid delivery response shape is invalid.');
    publicQuizAssert($oldJson['status'] === 'started' && $oldJson['attempt_uuid'] === $oldAttemptUuid, 'Valid delivery identity is invalid.');
    publicQuizAssert($oldJson['quiz']['name'] === 'Delivery Version 1' && $oldJson['quiz']['questions'][0]['text'] === 'Historical question V1 earlier', 'Closed historical batch did not deliver version one.');
    publicQuizAssert(count($oldJson['quiz']['questions']) === 2 && count($oldJson['quiz']['questions'][0]['options']) === 2, 'Question or option count is wrong.');
    publicQuizAssert($oldJson['quiz']['questions'][0]['order'] === 10 && $oldJson['quiz']['questions'][0]['options'][0]['order'] === 10, 'Snapshot ordering is not deterministic.');
    $serialized = json_encode($oldJson, JSON_THROW_ON_ERROR);
    foreach (['weight', 'program_id', 'program_code', 'raw_score', 'percentage', 'participant_id', 'campaign_id', 'campaign_batch_id', 'quiz_version_id', 'full_name', 'phone'] as $forbidden) {
        publicQuizAssert(!str_contains($serialized, $forbidden), 'Public delivery leaked ' . $forbidden . '.');
    }
    publicQuizAssert($beforeDelivery === publicQuizCounts($connection), 'Quiz delivery mutated persistence.');

    $newResponse = $router->dispatch(publicQuizRequest($newAttemptUuid, $newVisitor));
    $newJson = json_decode($newResponse->body, true, 512, JSON_THROW_ON_ERROR);
    publicQuizAssert($newResponse->status === 200 && $newJson['quiz']['name'] === 'Delivery Version 2' && $newJson['quiz']['questions'][0]['text'] === 'Historical question V2 earlier', 'Post-reset attempt did not deliver version two.');
    publicQuizAssert(!str_contains(json_encode($newJson, JSON_THROW_ON_ERROR), 'Historical question V1'), 'Post-reset delivery leaked version one content.');

    foreach ([
        publicQuizRequest('94000000-0000-4000-8000-000000000001', $oldVisitor),
        publicQuizRequest($oldAttemptUuid, '92000000-0000-4000-8000-000000000099'),
        publicQuizRequest($oldAttemptUuid, null),
        publicQuizRequest($oldAttemptUuid, 'invalid-cookie'),
        publicQuizRequest('not-a-uuid', $oldVisitor),
    ] as $request) {
        $response = $router->dispatch($request);
        publicQuizAssert($response->status === 404 && $response->body === '{"ok":false,"error":"not_found"}' && !isset($response->headers['Set-Cookie']), 'Not-found delivery semantics are unsafe.');
    }

    $connection->prepare('UPDATE attempts SET status = :status, submitted_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['status' => 'completed', 'id' => $newAttempt->id]);
    $completed = $router->dispatch(publicQuizRequest($newAttemptUuid, $newVisitor));
    publicQuizAssert($completed->status === 409 && $completed->body === '{"ok":false,"error":"attempt_completed"}' && !str_contains($completed->body, 'quiz'), 'Completed attempt quiz delivery is unsafe.');
    publicQuizAssert($router->dispatch(new Request('GET', '/go/delivery'))->status === 200, 'Valid public campaign entry changed.');
    $connection->prepare('UPDATE campaigns SET status = :status WHERE id = :id')->execute(['status' => 'draft', 'id' => $campaignId]);
    publicQuizAssert($router->dispatch(publicQuizRequest($oldAttemptUuid, $oldVisitor))->status === 200, 'Inactive campaign blocked historical delivery.');
    publicQuizAssert(
        $router->dispatch(new Request('GET', '/'))->status === 200
        && $router->dispatch(new Request('GET', '/health'))->status === 200
        && $router->dispatch(new Request('GET', '/api/public/start/delivery'))->status === 405,
        'Existing route behavior changed.',
    );
    foreach (['/result', '/result/dkv', '/result/mplb', '/result/pm', '/result/tie', '/result/error'] as $path) {
        publicQuizAssert($router->dispatch(new Request('GET', $path))->status === 200, 'Result preview route changed: ' . $path);
    }

    $connection->prepare('UPDATE campaigns SET status = :status WHERE id = :id')->execute(['status' => 'active', 'id' => $campaignId]);
    $startPayload = json_encode([
        'full_name' => 'Route Regression Participant',
        'origin_school' => null,
        'class_name' => null,
        'phone' => null,
        'marketing_consent' => false,
    ], JSON_THROW_ON_ERROR);
    $startUuid = '93000000-0000-4000-8000-000000000003';
    $startResponse = $router->dispatch(new Request(
        'POST',
        '/api/public/start/delivery',
        ['Content-Type' => 'application/json', 'Idempotency-Key' => $startUuid],
        $startPayload,
    ));
    $startVisitor = publicQuizCookieUuid((string) ($startResponse->headers['Set-Cookie'] ?? ''));
    $startRetry = $router->dispatch(new Request(
        'POST',
        '/api/public/start/delivery',
        ['Content-Type' => 'application/json', 'Idempotency-Key' => $startUuid],
        $startPayload,
        ['smk_match_visitor' => $startVisitor],
    ));
    publicQuizAssert(
        $startResponse->status === 201
        && $startRetry->status === 200
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $startVisitor) === 1,
        'R7 public start regression failed.',
    );

    echo "Public participant quiz HTTP tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . PUBLIC_QUIZ_HTTP_TEST_DATABASE);
    }
}
