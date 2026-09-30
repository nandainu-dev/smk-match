<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Attempt;
use App\Core\AttemptRepository;
use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\CampaignService;
use App\Core\Config;
use App\Core\Database;
use App\Core\ParticipantStartService;
use App\Core\ParticipantRepository;
use App\Core\QuizVersionRepository;
use App\Core\UuidV4Generator;

const PARTICIPANT_START_TEST_DATABASE = 'smk_match_g11_r6_test';

function startAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function startRejected(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message);
}

function startUtc(DateTimeImmutable $timestamp): string
{
    return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function startApplyMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $sql = file_get_contents($path);
        startAssert($sql !== false, 'Migration could not be read.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @param array<string, int> $programIds @return array{id: int, questions: array<int, array{id: int, options: array<int, int>}>} */
function startCreateVersion(PDO $connection, int $quizId, array $programIds, int $number, string $status): array
{
    $connection->prepare('INSERT INTO quiz_versions (quiz_id, version_number, status, name, published_at, created_at) VALUES (:quiz_id, :number, :status, :name, :published_at, UTC_TIMESTAMP())')->execute([
        'quiz_id' => $quizId, 'number' => $number, 'status' => $status, 'name' => 'Start Version ' . $number, 'published_at' => $status === 'published' ? '2026-03-11 00:00:00' : null,
    ]);
    $versionId = (int) $connection->lastInsertId();
    $membership = $connection->prepare('INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at) VALUES (:version_id, :program_id, UTC_TIMESTAMP())');
    $presentation = $connection->prepare("INSERT INTO quiz_version_program_presentations (quiz_version_program_id, program_code_snapshot, program_name_snapshot, personality_title_snapshot, mascot_path_snapshot, description_snapshot, skills_snapshot, snapshot_provenance, created_at, updated_at) SELECT :membership_id, short_name, name, personality_title, mascot_path, description, skills_json, 'version_snapshot', UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM programs WHERE id = :program_id");
    foreach ($programIds as $programId) {
        $membership->execute(['version_id' => $versionId, 'program_id' => $programId]);
        $presentation->execute(['membership_id' => (int) $connection->lastInsertId(), 'program_id' => $programId]);
    }
    $insertQuestion = $connection->prepare('INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at) VALUES (:version_id, :prompt, :order, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $insertOption = $connection->prepare('INSERT INTO question_options (question_id, option_text, sort_order, created_at) VALUES (:question_id, :text, :order, UTC_TIMESTAMP())');
    $insertWeight = $connection->prepare('INSERT INTO option_weights (question_option_id, program_id, weight, created_at) VALUES (:option_id, :program_id, :weight, UTC_TIMESTAMP())');
    $questions = [];
    foreach ([1, 2] as $questionOrder) {
        $insertQuestion->execute(['version_id' => $versionId, 'prompt' => 'Question ' . $questionOrder, 'order' => $questionOrder]);
        $questionId = (int) $connection->lastInsertId();
        $options = [];
        foreach ([1, 2] as $optionOrder) {
            $insertOption->execute(['question_id' => $questionId, 'text' => 'Option ' . $questionOrder . '-' . $optionOrder, 'order' => $optionOrder]);
            $optionId = (int) $connection->lastInsertId();
            $options[$optionOrder] = $optionId;
            foreach ($programIds as $programId) {
                $insertWeight->execute(['option_id' => $optionId, 'program_id' => $programId, 'weight' => (float) $optionOrder]);
            }
        }
        $questions[$questionOrder] = ['id' => $questionId, 'options' => $options];
    }

    return ['id' => $versionId, 'questions' => $questions];
}

function startCreateBatch(PDO $connection, int $campaignId, int $versionId, int $number, string $status, ?int $activeMarker): int
{
    $connection->prepare('INSERT INTO campaign_batches (campaign_id, batch_number, quiz_version_id, label, status, active_marker, started_at, closed_at, created_at, updated_at) VALUES (:campaign_id, :number, :version_id, :label, :status, :active_marker, UTC_TIMESTAMP(), :closed_at, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([
        'campaign_id' => $campaignId, 'number' => $number, 'version_id' => $versionId, 'label' => 'Batch ' . $number, 'status' => $status, 'active_marker' => $activeMarker, 'closed_at' => $status === 'closed' ? '2026-03-11 00:00:00' : null,
    ]);

    return (int) $connection->lastInsertId();
}

function startService(Database $database): ParticipantStartService
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

function startCount(PDO $connection, string $table): int
{
    return (int) $connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
startAssert($host === '127.0.0.1' && $port === '3306' && $databaseName === PARTICIPANT_START_TEST_DATABASE, 'Unsafe test database configuration.');
startAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $admin->exec('DROP DATABASE IF EXISTS ' . PARTICIPANT_START_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . PARTICIPANT_START_TEST_DATABASE . ' CHARACTER SET utf8mb4');
    $database = new Database(Config::load(SMK_MATCH_ROOT));
    $connection = $database->connection();
    startApplyMigrations($connection);
    $connection->prepare('INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES (:uuid, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['uuid' => '71000000-0000-4000-8000-000000000001', 'name' => 'Start School']);
    $schoolId = (int) $connection->lastInsertId();
    $programIds = [];
    $program = $connection->prepare('INSERT INTO programs (school_id, name, short_name, created_at, updated_at) VALUES (:school_id, :name, :code, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    foreach (['ALPHA', 'BETA', 'GAMMA', 'DELTA'] as $code) {
        $program->execute(['school_id' => $schoolId, 'name' => 'Program ' . $code, 'code' => $code]);
        $programIds[$code] = (int) $connection->lastInsertId();
    }
    $connection->prepare('INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES (:school_id, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'name' => 'Start Quiz']);
    $quizId = (int) $connection->lastInsertId();
    $versionOne = startCreateVersion($connection, $quizId, $programIds, 1, 'published');
    $versionTwo = startCreateVersion($connection, $quizId, $programIds, 2, 'published');
    $draftVersion = startCreateVersion($connection, $quizId, $programIds, 3, 'draft');
    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'quiz_id' => $quizId, 'version_id' => $versionOne['id'], 'name' => 'Start Campaign', 'status' => 'active']);
    $campaignId = (int) $connection->lastInsertId();
    $batchOneId = startCreateBatch($connection, $campaignId, $versionOne['id'], 1, 'active', 1);
    $startedAt = new DateTimeImmutable('2026-03-11 15:30:00', new DateTimeZone('Asia/Jakarta'));
    $service = startService($database);

    $first = $service->start($campaignId, '73000000-0000-4000-8000-000000000001', '74000000-0000-4000-8000-000000000001', 'First Participant', 'External School', 'Class 10', '081200000001', false, $startedAt);
    startAssert(!$first->alreadyStarted && $first->participant->schoolId === $schoolId && $first->participant->marketingConsent === false && $first->participant->marketingConsentAt === null && $first->attempt->status === Attempt::STATUS_STARTED && $first->attempt->submittedAt === null && $first->attempt->campaignId === $campaignId && $first->attempt->campaignBatchId === $batchOneId && $first->attempt->quizVersionId === $versionOne['id'], 'Valid start did not persist the active batch binding.');
    startAssert(startCount($connection, 'responses') === 0 && startCount($connection, 'results') === 0 && startCount($connection, 'result_scores') === 0 && startCount($connection, 'result_tied_programs') === 0, 'Start created scoring data.');

    $consenting = $service->start($campaignId, '73000000-0000-4000-8000-000000000002', '74000000-0000-4000-8000-000000000002', 'Consenting Participant', null, null, null, true, $startedAt);
    startAssert($consenting->participant->marketingConsent && $consenting->participant->marketingConsentAt === startUtc($startedAt), 'Consent true did not persist the trusted start timestamp.');

    $beforeRetry = [startCount($connection, 'participants'), startCount($connection, 'attempts')];
    $retry = $service->start($campaignId, $first->attempt->visitorUuid, $first->attempt->attemptUuid, 'Changed Name', null, 'Changed Class', '089999999999', true, $startedAt);
    startAssert($retry->alreadyStarted && $retry->attempt->id === $first->attempt->id && $retry->participant->id === $first->participant->id && $retry->participant->fullName === 'First Participant' && $beforeRetry === [startCount($connection, 'participants'), startCount($connection, 'attempts')], 'Idempotent retry mutated start identity or created rows.');

    (new AttemptRepository($database))->markCompleted($first->attempt->id, $startedAt);
    $completedRetry = $service->start($campaignId, $first->attempt->visitorUuid, $first->attempt->attemptUuid, 'Ignored', null, null, null, false, $startedAt);
    startAssert($completedRetry->alreadyStarted && $completedRetry->attempt->status === Attempt::STATUS_COMPLETED && $completedRetry->attempt->id === $first->attempt->id, 'Completed attempt start retry was not idempotent.');

    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'quiz_id' => $quizId, 'version_id' => $versionOne['id'], 'name' => 'Second Campaign', 'status' => 'active']);
    $secondCampaignId = (int) $connection->lastInsertId();
    startCreateBatch($connection, $secondCampaignId, $versionOne['id'], 1, 'active', 1);
    startRejected(fn() => $service->start($secondCampaignId, $first->attempt->visitorUuid, $first->attempt->attemptUuid, 'Ignored', null, null, null, false, $startedAt), 'Attempt UUID campaign conflict was accepted.');
    startRejected(fn() => $service->start($campaignId, '73000000-0000-4000-8000-000000000099', $first->attempt->attemptUuid, 'Ignored', null, null, null, false, $startedAt), 'Attempt UUID visitor conflict was accepted.');
    startRejected(fn() => $service->start(999999, '73000000-0000-4000-8000-000000000010', '74000000-0000-4000-8000-000000000010', 'Unknown Campaign', null, null, null, false, $startedAt), 'Unknown campaign was accepted.');

    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'quiz_id' => $quizId, 'version_id' => $versionOne['id'], 'name' => 'Draft Campaign', 'status' => 'draft']);
    $inactiveCampaignId = (int) $connection->lastInsertId();
    startCreateBatch($connection, $inactiveCampaignId, $versionOne['id'], 1, 'active', 1);
    startRejected(fn() => $service->start($inactiveCampaignId, '73000000-0000-4000-8000-000000000011', '74000000-0000-4000-8000-000000000011', 'Inactive', null, null, null, false, $startedAt), 'Inactive campaign was accepted.');
    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'quiz_id' => $quizId, 'version_id' => $versionOne['id'], 'name' => 'No Batch Campaign', 'status' => 'active']);
    $noBatchCampaignId = (int) $connection->lastInsertId();
    startRejected(fn() => $service->start($noBatchCampaignId, '73000000-0000-4000-8000-000000000012', '74000000-0000-4000-8000-000000000012', 'No Batch', null, null, null, false, $startedAt), 'Campaign without active batch was accepted.');

    $connection->prepare('UPDATE campaigns SET quiz_version_id = :version_id WHERE id = :id')->execute(['version_id' => $versionTwo['id'], 'id' => $campaignId]);
    $preReset = $service->start($campaignId, '73000000-0000-4000-8000-000000000003', '74000000-0000-4000-8000-000000000003', 'Pre Reset', null, null, null, false, $startedAt);
    startAssert($preReset->attempt->campaignBatchId === $batchOneId && $preReset->attempt->quizVersionId === $versionOne['id'], 'Campaign configuration overrode the active batch snapshot.');
    $newBatch = (new CampaignService($database, new CampaignRepository($database), new CampaignBatchRepository($database), new QuizVersionRepository($database)))->resetActiveBatch($campaignId, $startedAt);
    $postReset = $service->start($campaignId, '73000000-0000-4000-8000-000000000004', '74000000-0000-4000-8000-000000000004', 'Post Reset', null, null, null, false, $startedAt);
    startAssert($newBatch->quizVersionId === $versionTwo['id'] && $preReset->attempt->campaignBatchId === $batchOneId && $postReset->attempt->campaignBatchId === $newBatch->id && $postReset->attempt->quizVersionId === $versionTwo['id'], 'Reset did not preserve old bindings and select the new active batch.');

    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school_id' => $schoolId, 'quiz_id' => $quizId, 'version_id' => $draftVersion['id'], 'name' => 'Draft Version Campaign', 'status' => 'active']);
    $draftCampaignId = (int) $connection->lastInsertId();
    startCreateBatch($connection, $draftCampaignId, $draftVersion['id'], 1, 'active', 1);
    startRejected(fn() => $service->start($draftCampaignId, '73000000-0000-4000-8000-000000000013', '74000000-0000-4000-8000-000000000013', 'Draft Version', null, null, null, false, $startedAt), 'Non-published batch version was accepted.');

    $participantsBeforeFailure = startCount($connection, 'participants');
    $attemptsBeforeFailure = startCount($connection, 'attempts');
    $connection->exec("CREATE TRIGGER start_attempt_failure BEFORE INSERT ON attempts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced attempt failure'");
    try {
        startRejected(fn() => $service->start($campaignId, '73000000-0000-4000-8000-000000000014', '74000000-0000-4000-8000-000000000014', 'Rollback Participant', null, null, null, false, $startedAt), 'Forced attempt failure was accepted.');
    } finally {
        $connection->exec('DROP TRIGGER start_attempt_failure');
    }
    startAssert(startCount($connection, 'participants') === $participantsBeforeFailure && startCount($connection, 'attempts') === $attemptsBeforeFailure, 'Failed attempt creation left an orphan participant.');

    echo "Participant start service tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . PARTICIPANT_START_TEST_DATABASE);
    }
}
