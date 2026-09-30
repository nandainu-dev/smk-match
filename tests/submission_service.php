<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Attempt;
use App\Core\AttemptRepository;
use App\Core\AttemptResponseRepository;
use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\Config;
use App\Core\Database;
use App\Core\ParticipantRepository;
use App\Core\QuizVersionRepository;
use App\Core\QuizVersionProgramRepository;
use App\Core\ResultRepository;
use App\Core\ResultScoreRepository;
use App\Core\ResultTiedProgramRepository;
use App\Core\ScoringEngine;
use App\Core\SubmissionService;

const SUBMISSION_SERVICE_TEST_DATABASE = 'smk_match_g11_r5_test';

function submissionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function submissionRejected(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message);
}

function submissionUtc(DateTimeImmutable $timestamp): string
{
    return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function submissionApplyMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migrationPath) {
        $sql = file_get_contents($migrationPath);
        submissionAssert($sql !== false, 'Migration could not be read.');

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @return array<string, mixed> */
function submissionFixture(PDO $connection, Database $database): array
{
    $connection->prepare('INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES (:uuid, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([
        'uuid' => '61000000-0000-4000-8000-000000000001',
        'name' => 'Submission School',
    ]);
    $schoolId = (int) $connection->lastInsertId();
    $insertProgram = $connection->prepare('INSERT INTO programs (school_id, name, short_name, created_at, updated_at) VALUES (:school_id, :name, :code, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $programIds = [];
    foreach (['ALPHA', 'BETA', 'GAMMA', 'DELTA'] as $code) {
        $insertProgram->execute(['school_id' => $schoolId, 'name' => 'Program ' . $code, 'code' => $code]);
        $programIds[$code] = (int) $connection->lastInsertId();
    }

    $connection->prepare('INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES (:school_id, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([
        'school_id' => $schoolId,
        'name' => 'Submission Quiz',
    ]);
    $quizId = (int) $connection->lastInsertId();
    $version = submissionCreateVersion($connection, $quizId, $programIds, 1, 'published');

    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :quiz_version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([
        'school_id' => $schoolId,
        'quiz_id' => $quizId,
        'quiz_version_id' => $version['id'],
        'name' => 'Submission Campaign',
        'status' => 'active',
    ]);
    $campaignId = (int) $connection->lastInsertId();
    $batchId = submissionCreateBatch($connection, $campaignId, $version['id'], 1, 'active', 1);

    $timestamp = new DateTimeImmutable('2026-03-09 11:30:00', new DateTimeZone('Asia/Jakarta'));
    $participant = (new ParticipantRepository($database))->create(
        $schoolId,
        '62000000-0000-4000-8000-000000000001',
        'Submission Participant',
        null,
        null,
        null,
        false,
        null,
        $timestamp,
        $timestamp,
    );

    return [
        'school_id' => $schoolId,
        'program_ids' => $programIds,
        'quiz_id' => $quizId,
        'version' => $version,
        'campaign_id' => $campaignId,
        'batch_id' => $batchId,
        'participant' => $participant,
        'timestamp' => $timestamp,
    ];
}

/** @param array<string, int> $programIds @return array<string, mixed> */
function submissionCreateVersion(PDO $connection, int $quizId, array $programIds, int $versionNumber, string $status): array
{
    $connection->prepare('INSERT INTO quiz_versions (quiz_id, version_number, status, name, published_at, created_at) VALUES (:quiz_id, :number, :status, :name, :published_at, UTC_TIMESTAMP())')->execute([
        'quiz_id' => $quizId,
        'number' => $versionNumber,
        'status' => $status,
        'name' => 'Submission Version ' . $versionNumber,
        'published_at' => $status === 'published' ? '2026-03-09 00:00:00' : null,
    ]);
    $versionId = (int) $connection->lastInsertId();
    $membership = $connection->prepare('INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at) VALUES (:version_id, :program_id, UTC_TIMESTAMP())');
    $presentation = $connection->prepare("INSERT INTO quiz_version_program_presentations (quiz_version_program_id, program_code_snapshot, program_name_snapshot, personality_title_snapshot, mascot_path_snapshot, description_snapshot, skills_snapshot, snapshot_provenance, created_at, updated_at) SELECT :membership_id, short_name, name, personality_title, mascot_path, description, skills_json, 'version_snapshot', UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM programs WHERE id = :program_id");
    foreach ($programIds as $programId) {
        $membership->execute(['version_id' => $versionId, 'program_id' => $programId]);
        $presentation->execute(['membership_id' => (int) $connection->lastInsertId(), 'program_id' => $programId]);
    }

    $questions = [];
    $insertQuestion = $connection->prepare('INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at) VALUES (:version_id, :prompt, :sort_order, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $insertOption = $connection->prepare('INSERT INTO question_options (question_id, option_text, sort_order, created_at) VALUES (:question_id, :text, :sort_order, UTC_TIMESTAMP())');
    $insertWeight = $connection->prepare('INSERT INTO option_weights (question_option_id, program_id, weight, created_at) VALUES (:option_id, :program_id, :weight, UTC_TIMESTAMP())');
    $weightSets = [
        [
            ['ALPHA' => 4.0, 'BETA' => 1.0],
            ['BETA' => 4.0],
        ],
        [
            ['ALPHA' => 3.0, 'GAMMA' => 1.0],
            ['ALPHA' => 4.0],
        ],
    ];
    foreach ($weightSets as $questionIndex => $options) {
        $sortOrder = $questionIndex + 1;
        $insertQuestion->execute(['version_id' => $versionId, 'prompt' => 'Question ' . $sortOrder, 'sort_order' => $sortOrder]);
        $questionId = (int) $connection->lastInsertId();
        $optionIds = [];
        foreach ($options as $optionIndex => $weights) {
            $optionOrder = $optionIndex + 1;
            $insertOption->execute(['question_id' => $questionId, 'text' => 'Option ' . $sortOrder . '-' . $optionOrder, 'sort_order' => $optionOrder]);
            $optionId = (int) $connection->lastInsertId();
            $optionIds[$optionOrder] = $optionId;
            foreach ($weights as $code => $weight) {
                $insertWeight->execute(['option_id' => $optionId, 'program_id' => $programIds[$code], 'weight' => $weight]);
            }
        }
        $questions[$sortOrder] = ['id' => $questionId, 'options' => $optionIds];
    }

    return ['id' => $versionId, 'questions' => $questions];
}

function submissionCreateBatch(PDO $connection, int $campaignId, int $versionId, int $number, string $status, ?int $activeMarker): int
{
    $connection->prepare('INSERT INTO campaign_batches (campaign_id, batch_number, quiz_version_id, label, status, active_marker, started_at, closed_at, created_at, updated_at) VALUES (:campaign_id, :number, :version_id, :label, :status, :active_marker, UTC_TIMESTAMP(), :closed_at, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([
        'campaign_id' => $campaignId,
        'number' => $number,
        'version_id' => $versionId,
        'label' => 'Batch ' . $number,
        'status' => $status,
        'active_marker' => $activeMarker,
        'closed_at' => $status === 'closed' ? '2026-03-09 00:00:00' : null,
    ]);

    return (int) $connection->lastInsertId();
}

function submissionService(Database $database): SubmissionService
{
    return new SubmissionService(
        $database,
        new AttemptRepository($database),
        new AttemptResponseRepository($database),
        new ResultRepository($database),
        new ResultScoreRepository($database),
        new ResultTiedProgramRepository($database),
        new ParticipantRepository($database),
        new CampaignRepository($database),
        new CampaignBatchRepository($database),
        new QuizVersionRepository($database),
        new QuizVersionProgramRepository($database),
        new ScoringEngine(),
    );
}

/** @param array<string, mixed> $fixture @param array<int, int> $answers */
function submissionCreateAttempt(Database $database, array $fixture, string $suffix, array $answers): array
{
    $attempt = (new AttemptRepository($database))->create(
        $fixture['participant']->id,
        $fixture['campaign_id'],
        $fixture['batch_id'],
        $fixture['version']['id'],
        '63000000-0000-4000-8000-' . $suffix,
        '64000000-0000-4000-8000-' . $suffix,
        'test',
        $fixture['timestamp'],
    );

    return [$attempt, $answers];
}

/** @param array<string, mixed> $fixture @return array<int, int> */
function submissionAnswers(array $fixture, int $firstOption, int $secondOption): array
{
    return [
        $fixture['version']['questions'][1]['id'] => $fixture['version']['questions'][1]['options'][$firstOption],
        $fixture['version']['questions'][2]['id'] => $fixture['version']['questions'][2]['options'][$secondOption],
    ];
}

function submissionCount(PDO $connection, string $table, int $attemptId): int
{
    $column = $table === 'responses' ? 'attempt_id' : 'id';
    if ($table === 'results') {
        $statement = $connection->prepare('SELECT COUNT(*) FROM results WHERE attempt_id = :attempt_id');
        $statement->execute(['attempt_id' => $attemptId]);
    } elseif ($table === 'responses') {
        $statement = $connection->prepare('SELECT COUNT(*) FROM responses WHERE attempt_id = :attempt_id');
        $statement->execute(['attempt_id' => $attemptId]);
    } else {
        $statement = $connection->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE result_id IN (SELECT id FROM results WHERE attempt_id = :attempt_id)');
        $statement->execute(['attempt_id' => $attemptId]);
    }

    return (int) $statement->fetchColumn();
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
submissionAssert($host === '127.0.0.1' && $port === '3306' && $databaseName === SUBMISSION_SERVICE_TEST_DATABASE, 'Unsafe test database configuration.');
submissionAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $admin->exec('DROP DATABASE IF EXISTS ' . SUBMISSION_SERVICE_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . SUBMISSION_SERVICE_TEST_DATABASE . ' CHARACTER SET utf8mb4');
    $database = new Database(Config::load(SMK_MATCH_ROOT));
    $connection = $database->connection();
    submissionApplyMigrations($connection);
    $fixture = submissionFixture($connection, $database);
    $service = submissionService($database);
    $quizVersions = new QuizVersionRepository($database);
    $resultRepository = new ResultRepository($database);
    $responseRepository = new AttemptResponseRepository($database);

    $nonTieAnswers = submissionAnswers($fixture, 2, 1);
    [$nonTieAttempt] = submissionCreateAttempt($database, $fixture, '000000000001', $nonTieAnswers);
    $directDefinition = $quizVersions->findById($fixture['version']['id'])?->definition();
    submissionAssert($directDefinition !== null, 'Published snapshot could not hydrate.');
    $directG6 = (new ScoringEngine())->score($directDefinition, [
        ['question_id' => 'question-' . $fixture['version']['questions'][1]['id'], 'option_id' => 'option-' . $fixture['version']['questions'][1]['options'][2]],
        ['question_id' => 'question-' . $fixture['version']['questions'][2]['id'], 'option_id' => 'option-' . $fixture['version']['questions'][2]['options'][1]],
    ]);
    $outcome = $service->submit($nonTieAttempt->attemptUuid, $nonTieAnswers, $fixture['timestamp']);
    submissionAssert(
        !$outcome->alreadyCompleted
        && $outcome->result->totalRawScore === $directG6['total_raw_score']
        && $outcome->result->dominantProgramId === $fixture['program_ids'][$directG6['dominant_program']]
        && !$outcome->result->isTie
        && count($outcome->scores) === 4
        && $outcome->tiedProgramIds === [],
        'Non-tie submission did not persist the direct G6 result.',
    );
    $completedAttempt = (new AttemptRepository($database))->findById($nonTieAttempt->id);
    submissionAssert($completedAttempt?->status === Attempt::STATUS_COMPLETED && $completedAttempt->submittedAt === submissionUtc($fixture['timestamp']) && submissionCount($connection, 'responses', $nonTieAttempt->id) === 2, 'Non-tie attempt completion or responses are invalid.');
    foreach ($outcome->scores as $score) {
        $programCode = array_search($score->programId, $fixture['program_ids'], true);
        submissionAssert($programCode !== false && $score->rawScore === $directG6['raw_scores'][$programCode] && $score->normalizedPercentage === $directG6['percentages'][$programCode], 'Persisted score differs from direct G6 output.');
    }
    submissionAssert(
        array_map(static fn($score): string => (string) array_search($score->programId, $fixture['program_ids'], true), $outcome->scores) === array_column($directG6['ranking'], 'program')
        && array_map(static fn($score): ?int => $score->displayOrder, $outcome->scores) === [1, 2, 3, 4],
        'Non-tie result scores did not persist the exact one-based G6 ranking sequence.',
    );
    $beforeRetryScoreOrder = array_map(static fn($score): array => [$score->programId, $score->displayOrder], $outcome->scores);
    $beforeRetry = [submissionCount($connection, 'responses', $nonTieAttempt->id), submissionCount($connection, 'results', $nonTieAttempt->id), submissionCount($connection, 'result_scores', $nonTieAttempt->id), submissionCount($connection, 'result_tied_programs', $nonTieAttempt->id), $completedAttempt->submittedAt];
    $retry = $service->submit($nonTieAttempt->attemptUuid, submissionAnswers($fixture, 2, 2), new DateTimeImmutable('2026-03-10 00:00:00', new DateTimeZone('UTC')));
    $afterRetry = [submissionCount($connection, 'responses', $nonTieAttempt->id), submissionCount($connection, 'results', $nonTieAttempt->id), submissionCount($connection, 'result_scores', $nonTieAttempt->id), submissionCount($connection, 'result_tied_programs', $nonTieAttempt->id), (new AttemptRepository($database))->findById($nonTieAttempt->id)?->submittedAt];
    submissionAssert($retry->alreadyCompleted && $retry->result->id === $outcome->result->id && $beforeRetry === $afterRetry && array_map(static fn($score): array => [$score->programId, $score->displayOrder], $retry->scores) === $beforeRetryScoreOrder, 'Completed retry changed persisted state or display ordering.');

    $tieAnswers = submissionAnswers($fixture, 2, 2);
    [$tieAttempt] = submissionCreateAttempt($database, $fixture, '000000000002', $tieAnswers);
    $tieOutcome = $service->submit($tieAttempt->attemptUuid, $tieAnswers, $fixture['timestamp']);
    $tieG6 = (new ScoringEngine())->score($directDefinition, [
        ['question_id' => 'question-' . $fixture['version']['questions'][1]['id'], 'option_id' => 'option-' . $fixture['version']['questions'][1]['options'][2]],
        ['question_id' => 'question-' . $fixture['version']['questions'][2]['id'], 'option_id' => 'option-' . $fixture['version']['questions'][2]['options'][2]],
    ]);
    submissionAssert(
        !$tieOutcome->alreadyCompleted
        && $tieOutcome->result->isTie
        && $tieOutcome->result->dominantProgramId === null
        && count($tieOutcome->tiedProgramIds) === 2
        && count($tieOutcome->scores) === 4
        && array_map(static fn($score): string => (string) array_search($score->programId, $fixture['program_ids'], true), $tieOutcome->scores) === array_column($tieG6['ranking'], 'program')
        && array_map(static fn($score): ?int => $score->displayOrder, $tieOutcome->scores) === [1, 2, 3, 4],
        'Tie submission did not preserve sequential positions from the deterministic G6 ranking.',
    );

    submissionRejected(fn() => $service->submit('64000000-0000-4000-8000-999999999999', $nonTieAnswers, $fixture['timestamp']), 'Unknown attempt was accepted.');
    $connection->prepare('INSERT INTO attempts (quiz_version_id, visitor_uuid, attempt_uuid, source, status, submitted_at, created_at) VALUES (:version, :visitor, :attempt, NULL, :status, NULL, :created_at)')->execute([
        'version' => $fixture['version']['id'], 'visitor' => '63000000-0000-4000-8000-000000000099', 'attempt' => '64000000-0000-4000-8000-000000000099', 'status' => 'started', 'created_at' => submissionUtc($fixture['timestamp']),
    ]);
    submissionRejected(fn() => $service->submit('64000000-0000-4000-8000-000000000099', $nonTieAnswers, $fixture['timestamp']), 'Legacy null-binding attempt was accepted.');

    $attempts = new AttemptRepository($database);
    $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :quiz_version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([
        'school_id' => $fixture['school_id'], 'quiz_id' => $fixture['quiz_id'], 'quiz_version_id' => $fixture['version']['id'], 'name' => 'Other Campaign', 'status' => 'active',
    ]);
    $otherCampaignId = (int) $connection->lastInsertId();
    $otherCampaignBatchId = submissionCreateBatch($connection, $otherCampaignId, $fixture['version']['id'], 1, 'active', 1);
    $wrongCampaignAttempt = $attempts->create($fixture['participant']->id, $fixture['campaign_id'], $otherCampaignBatchId, $fixture['version']['id'], '63000000-0000-4000-8000-000000000101', '64000000-0000-4000-8000-000000000101', null, $fixture['timestamp']);
    submissionRejected(fn() => $service->submit($wrongCampaignAttempt->attemptUuid, $nonTieAnswers, $fixture['timestamp']), 'Campaign and batch mismatch was accepted.');

    $versionTwo = submissionCreateVersion($connection, $fixture['quiz_id'], $fixture['program_ids'], 2, 'published');
    $wrongVersionBatchId = submissionCreateBatch($connection, $fixture['campaign_id'], $versionTwo['id'], 2, 'closed', null);
    $wrongVersionAttempt = $attempts->create($fixture['participant']->id, $fixture['campaign_id'], $wrongVersionBatchId, $fixture['version']['id'], '63000000-0000-4000-8000-000000000102', '64000000-0000-4000-8000-000000000102', null, $fixture['timestamp']);
    submissionRejected(fn() => $service->submit($wrongVersionAttempt->attemptUuid, $nonTieAnswers, $fixture['timestamp']), 'Batch and version mismatch was accepted.');

    $connection->prepare('INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES (:uuid, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['uuid' => '61000000-0000-4000-8000-000000000002', 'name' => 'Other School']);
    $otherSchoolId = (int) $connection->lastInsertId();
    $otherParticipant = (new ParticipantRepository($database))->create($otherSchoolId, '62000000-0000-4000-8000-000000000002', 'Other School Participant', null, null, null, false, null, $fixture['timestamp'], $fixture['timestamp']);
    $wrongSchoolAttempt = $attempts->create($otherParticipant->id, $fixture['campaign_id'], $fixture['batch_id'], $fixture['version']['id'], '63000000-0000-4000-8000-000000000103', '64000000-0000-4000-8000-000000000103', null, $fixture['timestamp']);
    submissionRejected(fn() => $service->submit($wrongSchoolAttempt->attemptUuid, $nonTieAnswers, $fixture['timestamp']), 'Participant and campaign school mismatch was accepted.');

    $draftVersion = submissionCreateVersion($connection, $fixture['quiz_id'], $fixture['program_ids'], 3, 'draft');
    $draftBatchId = submissionCreateBatch($connection, $fixture['campaign_id'], $draftVersion['id'], 3, 'closed', null);
    $draftAttempt = $attempts->create($fixture['participant']->id, $fixture['campaign_id'], $draftBatchId, $draftVersion['id'], '63000000-0000-4000-8000-000000000104', '64000000-0000-4000-8000-000000000104', null, $fixture['timestamp']);
    submissionRejected(fn() => $service->submit($draftAttempt->attemptUuid, submissionAnswers(['version' => $draftVersion], 1, 1), $fixture['timestamp']), 'Non-published version was accepted.');

    [$closedBatchAttempt] = submissionCreateAttempt($database, $fixture, '000000000003', $nonTieAnswers);
    $connection->prepare('UPDATE campaign_batches SET status = :status, active_marker = NULL, closed_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['status' => 'closed', 'id' => $fixture['batch_id']]);
    submissionCreateBatch($connection, $fixture['campaign_id'], $fixture['version']['id'], 4, 'active', 1);
    $closedBatchOutcome = $service->submit($closedBatchAttempt->attemptUuid, $nonTieAnswers, $fixture['timestamp']);
    submissionAssert(!$closedBatchOutcome->alreadyCompleted && $closedBatchOutcome->result->isTie === false, 'Closed bound batch submission was rejected or rebound.');

    [$missingAttempt] = submissionCreateAttempt($database, $fixture, '000000000004', [$fixture['version']['questions'][1]['id'] => $fixture['version']['questions'][1]['options'][1]]);
    submissionRejected(fn() => $service->submit($missingAttempt->attemptUuid, [$fixture['version']['questions'][1]['id'] => $fixture['version']['questions'][1]['options'][1]], $fixture['timestamp']), 'Missing answer was accepted.');
    [$extraAttempt] = submissionCreateAttempt($database, $fixture, '000000000005', $nonTieAnswers);
    $extraAnswers = $nonTieAnswers; $extraAnswers[999999] = $fixture['version']['questions'][1]['options'][1];
    submissionRejected(fn() => $service->submit($extraAttempt->attemptUuid, $extraAnswers, $fixture['timestamp']), 'Extra answer was accepted.');
    [$wrongOptionAttempt] = submissionCreateAttempt($database, $fixture, '000000000006', $nonTieAnswers);
    $wrongOptionAnswers = submissionAnswers($fixture, 2, 1);
    $wrongOptionAnswers[$fixture['version']['questions'][1]['id']] = $fixture['version']['questions'][2]['options'][1];
    submissionRejected(fn() => $service->submit($wrongOptionAttempt->attemptUuid, $wrongOptionAnswers, $fixture['timestamp']), 'Wrong option ownership was accepted.');

    [$partialAttempt] = submissionCreateAttempt($database, $fixture, '000000000007', $nonTieAnswers);
    $responseRepository->create($partialAttempt->id, $fixture['version']['questions'][1]['id'], $fixture['version']['questions'][1]['options'][1], $fixture['timestamp']);
    submissionRejected(fn() => $service->submit($partialAttempt->attemptUuid, $nonTieAnswers, $fixture['timestamp']), 'Preexisting response was accepted.');
    submissionAssert(submissionCount($connection, 'responses', $partialAttempt->id) === 1 && $resultRepository->findByAttemptId($partialAttempt->id) === null, 'Preexisting response state was changed.');

    [$resultAttempt] = submissionCreateAttempt($database, $fixture, '000000000008', $nonTieAnswers);
    $resultRepository->create($resultAttempt->id, 1.0, $fixture['program_ids']['ALPHA'], false, $fixture['timestamp']);
    submissionRejected(fn() => $service->submit($resultAttempt->attemptUuid, $nonTieAnswers, $fixture['timestamp']), 'Started attempt with result was accepted.');
    [$missingResultAttempt] = submissionCreateAttempt($database, $fixture, '000000000009', $nonTieAnswers);
    $connection->prepare('UPDATE attempts SET status = :status, submitted_at = :submitted_at WHERE id = :id')->execute(['status' => 'completed', 'submitted_at' => submissionUtc($fixture['timestamp']), 'id' => $missingResultAttempt->id]);
    submissionRejected(fn() => $service->submit($missingResultAttempt->attemptUuid, $nonTieAnswers, $fixture['timestamp']), 'Completed attempt without result was accepted.');

    [$forcedFailureAttempt] = submissionCreateAttempt($database, $fixture, '000000000010', $nonTieAnswers);
    $connection->exec("CREATE TRIGGER submission_score_failure BEFORE INSERT ON result_scores FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced submission score failure'");
    try {
        submissionRejected(fn() => $service->submit($forcedFailureAttempt->attemptUuid, $nonTieAnswers, $fixture['timestamp']), 'Forced partial-write failure was accepted.');
    } finally {
        $connection->exec('DROP TRIGGER submission_score_failure');
    }
    $rolledBackAttempt = (new AttemptRepository($database))->findById($forcedFailureAttempt->id);
    submissionAssert(submissionCount($connection, 'responses', $forcedFailureAttempt->id) === 0 && submissionCount($connection, 'results', $forcedFailureAttempt->id) === 0 && submissionCount($connection, 'result_scores', $forcedFailureAttempt->id) === 0 && submissionCount($connection, 'result_tied_programs', $forcedFailureAttempt->id) === 0 && $rolledBackAttempt?->status === 'started' && $rolledBackAttempt->submittedAt === null, 'Forced failure did not roll back the full submission.');

    echo "Submission service tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . SUBMISSION_SERVICE_TEST_DATABASE);
    }
}
