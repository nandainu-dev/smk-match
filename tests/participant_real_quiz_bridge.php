<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\SmartLinkRepository;
use App\Core\SmartLinkService;

const PARTICIPANT_REAL_QUIZ_TEST_DATABASE = 'smk_match_g11_r9_test';

function realQuizAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function realQuizMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $sql = file_get_contents($path);
        realQuizAssert($sql !== false, 'Migration could not be read.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @return array<string, int> */
function realQuizCounts(PDO $connection): array
{
    $counts = [];
    foreach (['participants', 'attempts', 'responses', 'results'] as $table) {
        $counts[$table] = (int) $connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    return $counts;
}

$script = file_get_contents(SMK_MATCH_ROOT . '/public/assets/js/participant-quiz.js');
realQuizAssert(is_string($script), 'Participant JavaScript could not be read.');
foreach (['/api/public/start/${encodeURIComponent(alias)}', 'Idempotency-Key', '/api/public/quiz/${encodeURIComponent(attemptUuid)}', 'sessionStorage', 'crypto.randomUUID'] as $required) {
    realQuizAssert(str_contains($script, $required), 'Missing real participant JavaScript contract: ' . $required);
}
foreach (['Math.random', 'document.cookie', 'localStorage', 'SubmissionService', 'ScoringEngine', 'raw_score', 'raw_scores', 'normalized_percentage', 'dominant_program', 'tied_programs', 'weights'] as $forbidden) {
    realQuizAssert(!str_contains($script, $forbidden), 'Forbidden real participant JavaScript contract: ' . $forbidden);
}
realQuizAssert(substr_count($script, 'fetch(') === 2, 'Participant JavaScript must make exactly two network calls.');

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
realQuizAssert($host === '127.0.0.1' && $port === '3306' && $databaseName === PARTICIPANT_REAL_QUIZ_TEST_DATABASE, 'Unsafe test database configuration.');
realQuizAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('DROP DATABASE IF EXISTS ' . PARTICIPANT_REAL_QUIZ_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . PARTICIPANT_REAL_QUIZ_TEST_DATABASE . ' CHARACTER SET utf8mb4');
    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $connection = $database->connection();
    realQuizMigrations($connection);
    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('95000000-0000-4000-8000-000000000001', 'Real Entry School', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolId = (int) $connection->lastInsertId();
    foreach (['ALPHA', 'BETA', 'GAMMA'] as $code) {
        $connection->prepare('INSERT INTO programs (school_id, name, short_name, created_at, updated_at) VALUES (:school_id, :name, :code, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'name' => 'Program ' . $code, 'code' => $code]);
    }
    $connection->exec("INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES ({$schoolId}, 'Real Entry Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $quizId = (int) $connection->lastInsertId();
    $connection->prepare("INSERT INTO quiz_versions (quiz_id, version_number, status, name, published_at, created_at) VALUES (:quiz_id, 1, 'published', 'Real Entry Version', UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute(['quiz_id' => $quizId]);
    $versionId = (int) $connection->lastInsertId();
    $connection->prepare("INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, 'Real Entry Campaign', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute(['school_id' => $schoolId, 'quiz_id' => $quizId, 'version_id' => $versionId]);
    $campaignId = (int) $connection->lastInsertId();
    $connection->prepare("INSERT INTO campaign_batches (campaign_id, batch_number, quiz_version_id, label, status, active_marker, started_at, created_at, updated_at) VALUES (:campaign_id, 1, :version_id, 'Entry', 'active', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute(['campaign_id' => $campaignId, 'version_id' => $versionId]);
    $links = new SmartLinkService(new SmartLinkRepository($database), new CampaignRepository($database), new CampaignBatchRepository($database));
    $links->create($schoolId, $campaignId, 'Real Entry Link', 'real-entry');
    $routes = require SMK_MATCH_ROOT . '/routes/web.php';
    $router = $routes($config);
    $before = realQuizCounts($connection);
    $response = $router->dispatch(new Request('GET', '/play/real-entry'));
    $unknown = $router->dispatch(new Request('GET', '/play/unknown-alias'));
    realQuizAssert($response->status === 200 && str_contains($response->body, 'participant-quiz') && str_contains($response->body, '"mode":"real"') && str_contains($response->body, '"alias":"real-entry"'), 'Real participant entry is invalid.');
    foreach (['questions', 'weights', 'campaign_id', 'campaign_batch_id', 'quiz_version_id', 'participant_id', 'school_id', 'full_name'] as $forbidden) {
        realQuizAssert(!str_contains($response->body, $forbidden), 'Real participant entry leaked ' . $forbidden . '.');
    }
    realQuizAssert($unknown->status === 404 && $before === realQuizCounts($connection), 'Real participant entry lookup mutated persistence or handled unknown alias unsafely.');
    echo "Participant real quiz bridge tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . PARTICIPANT_REAL_QUIZ_TEST_DATABASE);
    }
}
