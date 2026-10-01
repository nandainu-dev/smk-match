<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\AdminAnalyticsController;
use App\Core\AdminAnalyticsService;
use App\Core\AdminCampaignRepository;
use App\Core\AdminSession;
use App\Core\AnalyticsReadRepository;
use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\CampaignService;
use App\Core\Config;
use App\Core\Database;
use App\Core\QuizDefinition;
use App\Core\QuizVersionProgramPresentationRepository;
use App\Core\QuizVersionRepository;
use App\Core\QuizVersionService;
use App\Core\Request;

const ADMIN_ANALYTICS_TEST_DATABASE = 'smk_match_g14_test';

function analyticsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function analyticsStatements(string $path): array
{
    $contents = file_get_contents($path);
    analyticsAssert($contents !== false, 'Migration could not be read.');

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

function analyticsMigrate(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migration) {
        foreach (analyticsStatements($migration) as $statement) {
            $connection->exec($statement);
        }
    }
}

function analyticsResetSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    session_id('');
    $_SESSION = [];
}

/** @param list<string> $programCodes */
function analyticsDefinition(string $name, array $programCodes): QuizDefinition
{
    $weights = [];
    foreach ($programCodes as $index => $code) {
        $weights[] = ['program' => $code, 'weight' => $index + 1];
    }

    return new QuizDefinition($name, 1, array_fill_keys($programCodes, true), [[
        'id' => 'analytics-question',
        'text' => 'Analytics question',
        'order' => 10,
        'options' => [
            ['id' => 'analytics-option-a', 'text' => 'Option A', 'order' => 10, 'weights' => $weights],
            ['id' => 'analytics-option-b', 'text' => 'Option B', 'order' => 20, 'weights' => $weights],
        ],
    ]]);
}

/** @return array<string, int> */
function analyticsVersionPrograms(PDO $connection, int $versionId): array
{
    $statement = $connection->prepare(
        'SELECT qvpp.program_code_snapshot, qvp.program_id
         FROM quiz_version_programs AS qvp
         INNER JOIN quiz_version_program_presentations AS qvpp ON qvpp.quiz_version_program_id = qvp.id
         WHERE qvp.quiz_version_id = :version_id'
    );
    $statement->execute(['version_id' => $versionId]);
    $programs = [];
    foreach ($statement->fetchAll() as $row) {
        $programs[(string) $row['program_code_snapshot']] = (int) $row['program_id'];
    }

    return $programs;
}

/** @param array<string, int> $programs @param array<string, float> $percentages */
function analyticsCompletedAttempt(PDO $connection, int $participantId, int $campaignId, int $batchId, int $versionId, array $programs, array $percentages, ?string $dominantCode, bool $isTie, string $createdAt): int
{
    $attempt = $connection->prepare(
        'INSERT INTO attempts (participant_id, campaign_id, campaign_batch_id, quiz_version_id, visitor_uuid, attempt_uuid, source, status, submitted_at, created_at)
         VALUES (:participant_id, :campaign_id, :batch_id, :version_id, :visitor_uuid, :attempt_uuid, :source, :status, :submitted_at, :created_at)'
    );
    $suffix = str_pad((string) random_int(1, 999999), 12, '0', STR_PAD_LEFT);
    $attempt->execute([
        'participant_id' => $participantId,
        'campaign_id' => $campaignId,
        'batch_id' => $batchId,
        'version_id' => $versionId,
        'visitor_uuid' => '11111111-1111-4111-8111-' . $suffix,
        'attempt_uuid' => '22222222-2222-4222-8222-' . $suffix,
        'source' => 'analytics-test',
        'status' => 'completed',
        'submitted_at' => $createdAt,
        'created_at' => $createdAt,
    ]);
    $attemptId = (int) $connection->lastInsertId();
    $result = $connection->prepare(
        'INSERT INTO results (attempt_id, total_raw_score, dominant_program_id, is_tie, created_at)
         VALUES (:attempt_id, 1, :dominant_program_id, :is_tie, :created_at)'
    );
    $result->execute([
        'attempt_id' => $attemptId,
        'dominant_program_id' => $dominantCode === null ? null : $programs[$dominantCode],
        'is_tie' => $isTie ? 1 : 0,
        'created_at' => $createdAt,
    ]);
    $resultId = (int) $connection->lastInsertId();
    $score = $connection->prepare(
        'INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, display_order, created_at)
         VALUES (:result_id, :program_id, 1, :percentage, :display_order, :created_at)'
    );
    $order = 1;
    foreach ($programs as $code => $programId) {
        $score->execute([
            'result_id' => $resultId,
            'program_id' => $programId,
            'percentage' => $percentages[$code],
            'display_order' => $order++,
            'created_at' => $createdAt,
        ]);
    }
    if ($isTie) {
        $tied = $connection->prepare('INSERT INTO result_tied_programs (result_id, program_id) VALUES (:result_id, :program_id)');
        foreach (array_keys($programs) as $index => $code) {
            if ($index >= 2) {
                break;
            }
            $tied->execute(['result_id' => $resultId, 'program_id' => $programs[$code]]);
        }
    }

    return $attemptId;
}

function analyticsStartedAttempt(PDO $connection, int $participantId, int $campaignId, int $batchId, int $versionId, string $createdAt): void
{
    $suffix = str_pad((string) random_int(1, 999999), 12, '0', STR_PAD_LEFT);
    $statement = $connection->prepare(
        'INSERT INTO attempts (participant_id, campaign_id, campaign_batch_id, quiz_version_id, visitor_uuid, attempt_uuid, source, status, submitted_at, created_at)
         VALUES (:participant_id, :campaign_id, :batch_id, :version_id, :visitor_uuid, :attempt_uuid, :source, :status, NULL, :created_at)'
    );
    $statement->execute([
        'participant_id' => $participantId,
        'campaign_id' => $campaignId,
        'batch_id' => $batchId,
        'version_id' => $versionId,
        'visitor_uuid' => '33333333-3333-4333-8333-' . $suffix,
        'attempt_uuid' => '44444444-4444-4444-8444-' . $suffix,
        'source' => 'analytics-test',
        'status' => 'started',
        'created_at' => $createdAt,
    ]);
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
analyticsAssert($host === '127.0.0.1' && $port === '3306' && $databaseName === ADMIN_ANALYTICS_TEST_DATABASE, 'Unsafe test database configuration.');
analyticsAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $admin->exec('DROP DATABASE IF EXISTS ' . ADMIN_ANALYTICS_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . ADMIN_ANALYTICS_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $connection = $database->connection();
    analyticsMigrate($connection);

    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('14141414-1414-4141-8141-141414141414', 'Analytics School A', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolA = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('15151515-1515-4151-8151-151515151515', 'Analytics School B', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolB = (int) $connection->lastInsertId();
    $programInsert = $connection->prepare('INSERT INTO programs (school_id, name, short_name, created_at, updated_at) VALUES (:school_id, :name, :code, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    foreach (['ALPHA', 'BETA', 'GAMMA', 'DELTA'] as $code) {
        $programInsert->execute(['school_id' => $schoolA, 'name' => $code . ' Snapshot', 'code' => $code]);
    }
    foreach (['ALPHA', 'BETA', 'GAMMA'] as $code) {
        $programInsert->execute(['school_id' => $schoolB, 'name' => 'Foreign ' . $code, 'code' => $code]);
    }

    $quizInsert = $connection->prepare('INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES (:school_id, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $quizInsert->execute(['school_id' => $schoolA, 'name' => 'Analytics Quiz One']);
    $quizOne = (int) $connection->lastInsertId();
    $quizInsert->execute(['school_id' => $schoolA, 'name' => 'Analytics Quiz Two']);
    $quizTwo = (int) $connection->lastInsertId();
    $quizInsert->execute(['school_id' => $schoolB, 'name' => 'Foreign Quiz']);
    $foreignQuiz = (int) $connection->lastInsertId();
    $versions = new QuizVersionRepository($database, new QuizVersionProgramPresentationRepository($database));
    $versionService = new QuizVersionService($versions);
    $versionOne = $versionService->getOrCreateDraft($quizOne, analyticsDefinition('Version one', ['ALPHA', 'BETA', 'GAMMA']));
    $versionService->publish($quizOne, $versionOne->id);
    $versionTwo = $versionService->getOrCreateDraft($quizTwo, analyticsDefinition('Version two', ['ALPHA', 'BETA', 'GAMMA', 'DELTA']));
    $versionService->publish($quizTwo, $versionTwo->id);
    $foreignVersion = $versionService->getOrCreateDraft($foreignQuiz, analyticsDefinition('Foreign', ['ALPHA', 'BETA', 'GAMMA']));
    $versionService->publish($foreignQuiz, $foreignVersion->id);

    $campaignInsert = $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $campaignInsert->execute(['school_id' => $schoolA, 'quiz_id' => $quizOne, 'version_id' => $versionOne->id, 'name' => 'Analytics Campaign A', 'status' => 'draft']);
    $campaignA = (int) $connection->lastInsertId();
    $campaignInsert->execute(['school_id' => $schoolA, 'quiz_id' => $quizTwo, 'version_id' => $versionTwo->id, 'name' => 'Analytics Campaign B', 'status' => 'draft']);
    $campaignB = (int) $connection->lastInsertId();
    $campaignInsert->execute(['school_id' => $schoolB, 'quiz_id' => $foreignQuiz, 'version_id' => $foreignVersion->id, 'name' => 'Foreign Campaign', 'status' => 'draft']);
    $foreignCampaign = (int) $connection->lastInsertId();
    $campaignService = new CampaignService($database, new CampaignRepository($database), new CampaignBatchRepository($database), $versions);
    $batchA1 = $campaignService->activateCampaign($campaignA, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));
    $batchA2 = $campaignService->resetActiveBatch($campaignA, new DateTimeImmutable('2026-01-02 00:00:00 UTC'));
    $batchB1 = $campaignService->activateCampaign($campaignB, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));
    $campaignService->activateCampaign($foreignCampaign, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));

    $connection->exec("INSERT INTO participants (school_id, public_uuid, full_name, origin_school, class_name, phone, marketing_consent, created_at, updated_at) VALUES ({$schoolA}, '16161616-1616-4161-8161-161616161616', 'Private Participant', 'Private Origin', 'XII', '08123456789', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $participant = (int) $connection->lastInsertId();
    $programsOne = analyticsVersionPrograms($connection, $versionOne->id);
    $programsTwo = analyticsVersionPrograms($connection, $versionTwo->id);
    analyticsCompletedAttempt($connection, $participant, $campaignA, $batchA1->id, $versionOne->id, $programsOne, ['ALPHA' => 60.0, 'BETA' => 30.0, 'GAMMA' => 10.0], 'ALPHA', false, '2026-01-01 17:00:00');
    analyticsCompletedAttempt($connection, $participant, $campaignA, $batchA1->id, $versionOne->id, $programsOne, ['ALPHA' => 50.0, 'BETA' => 50.0, 'GAMMA' => 0.0], null, true, '2026-01-01 17:05:00');
    analyticsStartedAttempt($connection, $participant, $campaignA, $batchA1->id, $versionOne->id, '2026-01-01 17:10:00');
    analyticsCompletedAttempt($connection, $participant, $campaignB, $batchB1->id, $versionTwo->id, $programsTwo, ['ALPHA' => 40.0, 'BETA' => 30.0, 'GAMMA' => 20.0, 'DELTA' => 10.0], 'ALPHA', false, '2026-01-01 17:15:00');

    $repository = new AnalyticsReadRepository($database);
    $adminRepository = new AdminCampaignRepository($database);
    $service = new AdminAnalyticsService($config, $adminRepository, $repository);
    $report = $service->read($schoolA, $campaignA, null, '2026-01-02', '2026-01-02');
    analyticsAssert($report['summary']['started_attempt_count'] === 3 && $report['summary']['completed_result_count'] === 2, 'Started/completed analytics population is incorrect.');
    analyticsAssert($report['summary']['decisive_result_count'] === 1 && $report['summary']['tie_count'] === 1 && $report['summary']['tie_rate']['rate'] === 0.5, 'Decisive/tie analytics metrics are incorrect.');
    analyticsAssert(count($report['batch_trends']) === 2 && $report['batch_trends'][1]['completed_result_count'] === 0 && $report['batch_trends'][1]['tie_rate']['rate'] === null, 'Zero-state batch analytics are incorrect.');
    $alpha = array_values(array_filter($report['program_cohorts'], static fn (array $cohort): bool => $cohort['program_code'] === 'ALPHA'))[0];
    analyticsAssert($alpha['dominant_count'] === 1 && $alpha['score_average_denominator'] === 2 && $alpha['score_average_percentage'] === 55.0, 'Persisted score average did not include tie scores with the correct denominator.');
    analyticsAssert(count($report['comparison_cohorts']) === 1 && $report['comparison_cohorts'][0]['program_count'] === 3, 'Historical comparison cohort is incorrect.');
    analyticsAssert($service->read($schoolA, null, null, null, null)['summary']['completed_result_count'] === 3, 'All-history analytics mixed or omitted owned campaigns.');
    analyticsAssert(count($service->read($schoolA, $campaignB, null, null, null)['program_cohorts']) === 4, 'N-program version cohort was not retained.');
    $connection->prepare('UPDATE programs SET name = :name WHERE short_name = :code AND school_id = :school_id')->execute(['name' => 'Mutable Catalog Name', 'code' => 'ALPHA', 'school_id' => $schoolA]);
    analyticsAssert($service->read($schoolA, $campaignA, null, null, null)['program_cohorts'][0]['program_name'] === 'ALPHA Snapshot', 'Analytics used mutable program catalog data.');
    try {
        $service->read($schoolA, null, $batchA1->id, null, null);
        throw new RuntimeException('Batch without campaign was accepted.');
    } catch (InvalidArgumentException) {
    }
    try {
        $service->read($schoolA, $foreignCampaign, null, null, null);
        throw new RuntimeException('Cross-school campaign was accepted.');
    } catch (RuntimeException) {
    }

    analyticsResetSession();
    $session = new AdminSession();
    $controller = new AdminAnalyticsController($config, $session, $service);
    analyticsAssert($controller->index(new Request('GET', '/admin/analytics'))->status === 302, 'Unauthenticated analytics route was accepted.');
    $session->start(false);
    $session->login(['admin_id' => 1, 'school_id' => $schoolA]);
    $response = $controller->index(new Request('GET', '/admin/analytics', [], '', [], false, ['campaign_id' => [(string) $campaignA], 'school_id' => [(string) $schoolB]]));
    analyticsAssert($response->status === 200 && str_contains($response->body, 'Analitik historis'), 'Authenticated analytics route did not render.');
    foreach (['Private Participant', 'Private Origin', '08123456789', 'marketing_consent', 'visitor_uuid', 'attempt_uuid', 'raw_score', 'class_name'] as $forbidden) {
        analyticsAssert(!str_contains($response->body, $forbidden), 'Analytics view leaked private data: ' . $forbidden);
    }

    $connection->prepare('INSERT INTO attempts (participant_id, campaign_id, campaign_batch_id, quiz_version_id, visitor_uuid, attempt_uuid, source, status, submitted_at, created_at) VALUES (:participant_id, :campaign_id, :batch_id, :version_id, :visitor_uuid, :attempt_uuid, :source, :status, :submitted_at, :created_at)')->execute(['participant_id' => $participant, 'campaign_id' => $campaignA, 'batch_id' => $batchA2->id, 'version_id' => $versionOne->id, 'visitor_uuid' => '17171717-1717-4171-8171-171717171717', 'attempt_uuid' => '18181818-1818-4181-8181-181818181818', 'source' => 'analytics-test', 'status' => 'completed', 'submitted_at' => '2026-01-03 00:00:00', 'created_at' => '2026-01-03 00:00:00']);
    $corruptAttempt = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO results (attempt_id, total_raw_score, dominant_program_id, is_tie, created_at) VALUES ({$corruptAttempt}, 1, {$programsOne['ALPHA']}, 0, '2026-01-03 00:00:00')");
    try {
        $repository->read($schoolA, $campaignA, $batchA2->id, null, null);
        throw new RuntimeException('Missing result scores were accepted.');
    } catch (RuntimeException $exception) {
        analyticsAssert(str_contains($exception->getMessage(), 'scores are incomplete'), 'Wrong missing-score invariant failure.');
    }
    $connection->exec('DELETE FROM quiz_version_program_presentations WHERE quiz_version_program_id = (SELECT id FROM quiz_version_programs WHERE quiz_version_id = ' . $versionTwo->id . ' LIMIT 1)');
    try {
        $repository->read($schoolA, $campaignB, null, null, null);
        throw new RuntimeException('Missing program snapshot was accepted.');
    } catch (RuntimeException $exception) {
        analyticsAssert(str_contains($exception->getMessage(), 'presentation snapshot is missing'), 'Wrong missing-snapshot invariant failure.');
    }
} finally {
    analyticsResetSession();
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . ADMIN_ANALYTICS_TEST_DATABASE);
    }
    putenv('DB_PASSWORD');
}

echo "Admin analytics integration tests passed.\n";
