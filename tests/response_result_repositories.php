<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\AttemptRepository;
use App\Core\AttemptResponseRepository;
use App\Core\Config;
use App\Core\Database;
use App\Core\ParticipantRepository;
use App\Core\Result;
use App\Core\ResultRepository;
use App\Core\ResultScoreRepository;
use App\Core\ResultTiedProgramRepository;

const RESPONSE_RESULT_TEST_DATABASE = 'smk_match_g11_r4_2_test';

function responseResultAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function responseResultInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function responseResultDatabaseRejected(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

function responseResultUtc(DateTimeImmutable $timestamp): string
{
    return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function responseResultApplyMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migrationPath) {
        $sql = file_get_contents($migrationPath);
        responseResultAssert($sql !== false, 'Migration could not be read: ' . $migrationPath);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @return array<string, mixed> */
function responseResultCreateFixture(PDO $connection, Database $database): array
{
    $connection->prepare(
        'INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES (:public_uuid, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'public_uuid' => '51000000-0000-4000-8000-000000000001',
        'name' => 'Response Result School',
    ]);
    $schoolId = (int) $connection->lastInsertId();

    $programIds = [];
    $insertProgram = $connection->prepare(
        'INSERT INTO programs (school_id, name, short_name, created_at, updated_at)
         VALUES (:school_id, :name, :short_name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    foreach (['ALPHA', 'BETA', 'GAMMA', 'DELTA'] as $programCode) {
        $insertProgram->execute([
            'school_id' => $schoolId,
            'name' => 'Program ' . $programCode,
            'short_name' => $programCode,
        ]);
        $programIds[$programCode] = (int) $connection->lastInsertId();
    }

    $connection->prepare(
        'INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES (:school_id, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'school_id' => $schoolId,
        'name' => 'Response Result Snapshot Quiz',
    ]);
    $quizId = (int) $connection->lastInsertId();

    $connection->prepare(
        'INSERT INTO quiz_versions (quiz_id, version_number, status, name, published_at, created_at)
         VALUES (:quiz_id, 1, :status, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'quiz_id' => $quizId,
        'status' => 'published',
        'name' => 'Response Result Snapshot v1',
    ]);
    $quizVersionId = (int) $connection->lastInsertId();

    $insertMembership = $connection->prepare(
        'INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at)
         VALUES (:quiz_version_id, :program_id, UTC_TIMESTAMP())'
    );
    $insertPresentation = $connection->prepare("INSERT INTO quiz_version_program_presentations (quiz_version_program_id, program_code_snapshot, program_name_snapshot, personality_title_snapshot, mascot_path_snapshot, description_snapshot, skills_snapshot, snapshot_provenance, created_at, updated_at) SELECT :membership_id, short_name, name, personality_title, mascot_path, description, skills_json, 'version_snapshot', UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM programs WHERE id = :program_id");
    foreach ($programIds as $programId) {
        $insertMembership->execute([
            'quiz_version_id' => $quizVersionId,
            'program_id' => $programId,
        ]);
        $insertPresentation->execute(['membership_id' => (int) $connection->lastInsertId(), 'program_id' => $programId]);
    }

    $questionIds = [];
    $optionIds = [];
    $insertQuestion = $connection->prepare(
        'INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at)
         VALUES (:quiz_version_id, :prompt, :sort_order, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $insertOption = $connection->prepare(
        'INSERT INTO question_options (question_id, option_text, sort_order, created_at)
         VALUES (:question_id, :option_text, :sort_order, UTC_TIMESTAMP())'
    );
    $insertWeight = $connection->prepare(
        'INSERT INTO option_weights (question_option_id, program_id, weight, created_at)
         VALUES (:question_option_id, :program_id, :weight, UTC_TIMESTAMP())'
    );
    foreach ([1, 2] as $questionOrder) {
        $insertQuestion->execute([
            'quiz_version_id' => $quizVersionId,
            'prompt' => 'Snapshot question ' . $questionOrder,
            'sort_order' => $questionOrder,
        ]);
        $questionId = (int) $connection->lastInsertId();
        $questionIds[] = $questionId;

        foreach ([1, 2] as $optionOrder) {
            $insertOption->execute([
                'question_id' => $questionId,
                'option_text' => 'Snapshot option ' . $questionOrder . '-' . $optionOrder,
                'sort_order' => $optionOrder,
            ]);
            $optionId = (int) $connection->lastInsertId();
            $optionIds[$questionId][$optionOrder] = $optionId;

            foreach ($programIds as $programId) {
                $insertWeight->execute([
                    'question_option_id' => $optionId,
                    'program_id' => $programId,
                    'weight' => (float) $optionOrder,
                ]);
            }
        }
    }

    $connection->prepare(
        'INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at)
         VALUES (:school_id, :quiz_id, :quiz_version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'school_id' => $schoolId,
        'quiz_id' => $quizId,
        'quiz_version_id' => $quizVersionId,
        'name' => 'Response Result Campaign',
        'status' => 'active',
    ]);
    $campaignId = (int) $connection->lastInsertId();

    $connection->prepare(
        'INSERT INTO campaign_batches (
            campaign_id,
            batch_number,
            quiz_version_id,
            label,
            status,
            active_marker,
            started_at,
            created_at,
            updated_at
        ) VALUES (
            :campaign_id,
            1,
            :quiz_version_id,
            :label,
            :status,
            1,
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP()
        )'
    )->execute([
        'campaign_id' => $campaignId,
        'quiz_version_id' => $quizVersionId,
        'label' => 'Response Result Batch',
        'status' => 'active',
    ]);
    $batchId = (int) $connection->lastInsertId();

    $timestamp = new DateTimeImmutable('2026-03-08 09:30:00', new DateTimeZone('Asia/Jakarta'));
    $participants = new ParticipantRepository($database);
    $participant = $participants->create(
        $schoolId,
        '52000000-0000-4000-8000-000000000001',
        'Response Result Participant',
        null,
        null,
        null,
        false,
        null,
        $timestamp,
        $timestamp,
    );

    $attempts = new AttemptRepository($database);
    $attempt = $attempts->create(
        $participant->id,
        $campaignId,
        $batchId,
        $quizVersionId,
        '53000000-0000-4000-8000-000000000001',
        '54000000-0000-4000-8000-000000000001',
        'fixture',
        $timestamp,
    );

    return [
        'school_id' => $schoolId,
        'program_ids' => $programIds,
        'quiz_version_id' => $quizVersionId,
        'campaign_id' => $campaignId,
        'batch_id' => $batchId,
        'participant_id' => $participant->id,
        'attempt' => $attempt,
        'attempts' => $attempts,
        'question_ids' => $questionIds,
        'option_ids' => $optionIds,
        'timestamp' => $timestamp,
    ];
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

responseResultAssert(
    $host === '127.0.0.1'
    && $port === '3306'
    && $databaseName === RESPONSE_RESULT_TEST_DATABASE,
    'Unsafe test database configuration.',
);
responseResultAssert(
    is_string($username) && $username !== '' && is_string($password),
    'Local database credentials are required.',
);

$admin = null;
try {
    $admin = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $admin->exec('DROP DATABASE IF EXISTS ' . RESPONSE_RESULT_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . RESPONSE_RESULT_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $database = new Database(Config::load(SMK_MATCH_ROOT));
    $connection = $database->connection();
    responseResultApplyMigrations($connection);
    $fixture = responseResultCreateFixture($connection, $database);

    $responses = new AttemptResponseRepository($database);
    $results = new ResultRepository($database);
    $scores = new ResultScoreRepository($database);
    $tiedPrograms = new ResultTiedProgramRepository($database);
    $attempt = $fixture['attempt'];
    $questionIds = $fixture['question_ids'];
    $optionIds = $fixture['option_ids'];
    $createdAt = $fixture['timestamp'];

    $responseSecond = $responses->create(
        $attempt->id,
        $questionIds[1],
        $optionIds[$questionIds[1]][2],
        $createdAt,
    );
    $responseFirst = $responses->create(
        $attempt->id,
        $questionIds[0],
        $optionIds[$questionIds[0]][1],
        $createdAt,
    );
    responseResultAssert(
        $responseFirst->attemptId === $attempt->id
        && $responseFirst->questionId === $questionIds[0]
        && $responseFirst->questionOptionId === $optionIds[$questionIds[0]][1]
        && $responseFirst->createdAt === responseResultUtc($createdAt),
        'Response creation did not retain exact answer identity.',
    );
    $storedResponses = $responses->findByAttemptId($attempt->id);
    responseResultAssert(
        array_map(static fn($response): int => $response->questionId, $storedResponses) === $questionIds,
        'Responses were not returned in deterministic question identity order.',
    );
    responseResultDatabaseRejected(
        fn() => $responses->create($attempt->id, $questionIds[0], $optionIds[$questionIds[0]][2], $createdAt),
        'Duplicate response question was overwritten or accepted.',
    );
    responseResultDatabaseRejected(
        fn() => $responses->create(999999, $questionIds[0], $optionIds[$questionIds[0]][1], $createdAt),
        'Invalid response attempt foreign key was accepted.',
    );
    responseResultDatabaseRejected(
        fn() => $responses->create($attempt->id, 999999, $optionIds[$questionIds[0]][1], $createdAt),
        'Invalid response question foreign key was accepted.',
    );
    responseResultDatabaseRejected(
        fn() => $responses->create($attempt->id, $questionIds[0], 999999, $createdAt),
        'Invalid response option foreign key was accepted.',
    );

    $programIds = $fixture['program_ids'];
    $nonTieResult = $results->create(
        $attempt->id,
        13.333333333333,
        $programIds['ALPHA'],
        false,
        $createdAt,
    );
    responseResultAssert(
        abs($nonTieResult->totalRawScore - 13.333333333333) < 0.000000000001
        && $nonTieResult->dominantProgramId === $programIds['ALPHA']
        && $nonTieResult->isTie === false
        && $nonTieResult->createdAt === responseResultUtc($createdAt),
        'Non-tie result did not hydrate exact values.',
    );
    responseResultAssert(
        $results->findById($nonTieResult->id)?->attemptId === $attempt->id
        && $results->findById(999999) === null
        && $results->findByAttemptId($attempt->id)?->id === $nonTieResult->id
        && $results->findByAttemptId(999999) === null,
        'Result exact finders failed.',
    );
    responseResultDatabaseRejected(
        fn() => $results->create($attempt->id, 1.0, $programIds['BETA'], false, $createdAt),
        'Duplicate result attempt was accepted.',
    );

    $scoreInputs = [
        'DELTA' => [4.25, 12.5],
        'ALPHA' => [13.333333333333, 33.33],
        'GAMMA' => [-2.5, -6.25],
        'BETA' => [25.0, 62.5],
    ];
    foreach ($scoreInputs as $programCode => [$rawScore, $percentage]) {
        $scores->create(
            $nonTieResult->id,
            $programIds[$programCode],
            $rawScore,
            $percentage,
            $createdAt,
        );
    }
    $storedScores = $scores->findScoresByResultId($nonTieResult->id);
    $expectedProgramOrder = array_values($programIds);
    sort($expectedProgramOrder, SORT_NUMERIC);
    responseResultAssert(
        count($storedScores) === 4
        && array_map(static fn($score): int => $score->programId, $storedScores) === $expectedProgramOrder,
        'N-program result scores were not preserved in deterministic program identity order.',
    );
    $alphaScore = array_values(array_filter(
        $storedScores,
        static fn($score): bool => $score->programId === $programIds['ALPHA'],
    ))[0];
    responseResultAssert(
        abs($alphaScore->rawScore - 13.333333333333) < 0.000000000001
        && abs($alphaScore->normalizedPercentage - 33.33) < 0.000000000001,
        'Result score precision was rounded by the repository.',
    );
    responseResultDatabaseRejected(
        fn() => $scores->create($nonTieResult->id, $programIds['ALPHA'], 1.0, 1.0, $createdAt),
        'Duplicate result score was accepted.',
    );
    responseResultDatabaseRejected(
        fn() => $scores->create(999999, $programIds['ALPHA'], 1.0, 1.0, $createdAt),
        'Invalid result score result foreign key was accepted.',
    );
    responseResultDatabaseRejected(
        fn() => $scores->create($nonTieResult->id, 999999, 1.0, 1.0, $createdAt),
        'Invalid result score program foreign key was accepted.',
    );

    $secondAttempt = $fixture['attempts']->create(
        $fixture['participant_id'],
        $fixture['campaign_id'],
        $fixture['batch_id'],
        $fixture['quiz_version_id'],
        '53000000-0000-4000-8000-000000000002',
        '54000000-0000-4000-8000-000000000002',
        null,
        $createdAt,
    );
    $tieResult = $results->create($secondAttempt->id, 20.0, null, true, $createdAt);
    responseResultAssert(
        $tieResult->isTie && $tieResult->dominantProgramId === null,
        'Tie result fabricated a dominant program.',
    );
    $tiedPrograms->create($tieResult->id, $programIds['DELTA']);
    $tiedPrograms->create($tieResult->id, $programIds['BETA']);
    $expectedTiedProgramIds = [$programIds['BETA'], $programIds['DELTA']];
    sort($expectedTiedProgramIds, SORT_NUMERIC);
    responseResultAssert(
        $tiedPrograms->findTiedProgramIdsByResultId($tieResult->id) === $expectedTiedProgramIds,
        'Tie members were not returned in deterministic program identity order.',
    );
    responseResultDatabaseRejected(
        fn() => $tiedPrograms->create($tieResult->id, $programIds['BETA']),
        'Duplicate tied program member was accepted.',
    );
    responseResultDatabaseRejected(
        fn() => $tiedPrograms->create(999999, $programIds['BETA']),
        'Invalid tied program result foreign key was accepted.',
    );
    responseResultDatabaseRejected(
        fn() => $tiedPrograms->create($tieResult->id, 999999),
        'Invalid tied program program foreign key was accepted.',
    );

    responseResultInvalid(
        fn() => new Result(1, 1, 1.0, $programIds['ALPHA'], true, '2026-03-08 00:00:00'),
        'Tie result domain accepted a dominant program.',
    );
    responseResultInvalid(
        fn() => $results->create($secondAttempt->id, NAN, null, true, $createdAt),
        'Non-finite result total was accepted.',
    );
    responseResultInvalid(
        fn() => $scores->create($nonTieResult->id, $programIds['ALPHA'], INF, 1.0, $createdAt),
        'Non-finite raw score was accepted.',
    );
    responseResultInvalid(
        fn() => $scores->create($nonTieResult->id, $programIds['ALPHA'], 1.0, -INF, $createdAt),
        'Non-finite percentage was accepted.',
    );

    $connection->beginTransaction();
    try {
        $transactionAttempt = $fixture['attempts']->create(
            $fixture['participant_id'],
            $fixture['campaign_id'],
            $fixture['batch_id'],
            $fixture['quiz_version_id'],
            '53000000-0000-4000-8000-000000000003',
            '54000000-0000-4000-8000-000000000003',
            null,
            $createdAt,
        );
        $responses->create(
            $transactionAttempt->id,
            $questionIds[0],
            $optionIds[$questionIds[0]][1],
            $createdAt,
        );
        $transactionResult = $results->create(
            $transactionAttempt->id,
            1.0,
            $programIds['ALPHA'],
            false,
            $createdAt,
        );
        $scores->create($transactionResult->id, $programIds['ALPHA'], 1.0, 100.0, $createdAt);
        responseResultAssert($connection->inTransaction(), 'Repository committed the caller transaction.');
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
    responseResultAssert(
        $fixture['attempts']->findByAttemptUuid('54000000-0000-4000-8000-000000000003') === null
        && $responses->findByAttemptId($transactionAttempt->id) === []
        && $results->findByAttemptId($transactionAttempt->id) === null,
        'Caller transaction rollback did not remove repository-created rows.',
    );

    responseResultAssert(
        !method_exists($responses, 'update')
        && !method_exists($results, 'update')
        && !method_exists($scores, 'update')
        && !method_exists($tiedPrograms, 'update')
        && !method_exists($responses, 'delete')
        && !method_exists($results, 'delete')
        && !method_exists($scores, 'delete')
        && !method_exists($tiedPrograms, 'delete'),
        'Response or result repository exposes a generic update or hard delete API.',
    );

    echo "Response and result repository tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . RESPONSE_RESULT_TEST_DATABASE);
    }
}
