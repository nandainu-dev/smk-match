<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Attempt;
use App\Core\AttemptRepository;
use App\Core\Config;
use App\Core\Database;
use App\Core\ParticipantRepository;
const PARTICIPANT_ATTEMPT_TEST_DATABASE = 'smk_match_g11_r4_1_test';

function participantAttemptAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function participantAttemptInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function participantAttemptLogic(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (LogicException) {
        return;
    }

    throw new RuntimeException($message);
}

function participantAttemptDatabaseRejected(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

function participantAttemptFormatUtc(DateTimeImmutable $timestamp): string
{
    return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function participantAttemptApplyMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migrationPath) {
        $sql = file_get_contents($migrationPath);
        participantAttemptAssert($sql !== false, 'Migration could not be read: ' . $migrationPath);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

function participantAttemptInsertSnapshot(PDO $connection, int $schoolId, int $programId): array
{
    $insertQuiz = $connection->prepare(
        'INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES (:school_id, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $insertQuiz->execute([
        'school_id' => $schoolId,
        'name' => 'Participant attempt snapshot quiz',
    ]);
    $quizId = (int) $connection->lastInsertId();

    $insertVersion = $connection->prepare(
        'INSERT INTO quiz_versions (quiz_id, version_number, status, name, published_at, created_at)
         VALUES (:quiz_id, :version_number, :status, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );

    $versionIds = [];
    foreach ([1, 2] as $versionNumber) {
        $insertVersion->execute([
            'quiz_id' => $quizId,
            'version_number' => $versionNumber,
            'status' => 'published',
            'name' => 'Participant attempt snapshot v' . $versionNumber,
        ]);
        $versionId = (int) $connection->lastInsertId();
        $versionIds[$versionNumber] = $versionId;

        $connection->prepare(
            'INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at)
             VALUES (:quiz_version_id, :program_id, UTC_TIMESTAMP())'
        )->execute([
            'quiz_version_id' => $versionId,
            'program_id' => $programId,
        ]);

        $insertQuestion = $connection->prepare(
            'INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at)
             VALUES (:quiz_version_id, :prompt, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $insertQuestion->execute([
            'quiz_version_id' => $versionId,
            'prompt' => 'Snapshot question ' . $versionNumber,
        ]);
        $questionId = (int) $connection->lastInsertId();

        $insertOption = $connection->prepare(
            'INSERT INTO question_options (question_id, option_text, sort_order, created_at)
             VALUES (:question_id, :option_text, :sort_order, UTC_TIMESTAMP())'
        );
        foreach ([1, 2] as $sortOrder) {
            $insertOption->execute([
                'question_id' => $questionId,
                'option_text' => 'Snapshot option ' . $sortOrder,
                'sort_order' => $sortOrder,
            ]);
            $optionId = (int) $connection->lastInsertId();
            $connection->prepare(
                'INSERT INTO option_weights (question_option_id, program_id, weight, created_at)
                 VALUES (:question_option_id, :program_id, :weight, UTC_TIMESTAMP())'
            )->execute([
                'question_option_id' => $optionId,
                'program_id' => $programId,
                'weight' => (float) $sortOrder,
            ]);
        }
    }

    return [
        'quiz_id' => $quizId,
        'version_one_id' => $versionIds[1],
        'version_two_id' => $versionIds[2],
    ];
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

participantAttemptAssert(
    $host === '127.0.0.1'
    && $port === '3306'
    && $databaseName === PARTICIPANT_ATTEMPT_TEST_DATABASE,
    'Unsafe test database configuration.',
);
participantAttemptAssert(
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
    $admin->exec('DROP DATABASE IF EXISTS ' . PARTICIPANT_ATTEMPT_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . PARTICIPANT_ATTEMPT_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $connection = $database->connection();
    participantAttemptApplyMigrations($connection);

    $participants = new ParticipantRepository($database);
    $attempts = new AttemptRepository($database);

    $connection->prepare(
        'INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES (:public_uuid, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'public_uuid' => '10000000-0000-4000-8000-000000000001',
        'name' => 'Participant Attempt School',
    ]);
    $schoolId = (int) $connection->lastInsertId();

    $connection->prepare(
        'INSERT INTO programs (school_id, name, short_name, created_at, updated_at)
         VALUES (:school_id, :name, :short_name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'school_id' => $schoolId,
        'name' => 'Snapshot Program',
        'short_name' => 'SNAP',
    ]);
    $programId = (int) $connection->lastInsertId();

    $snapshot = participantAttemptInsertSnapshot($connection, $schoolId, $programId);

    $connection->prepare(
        'INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at)
         VALUES (:school_id, :quiz_id, :quiz_version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'school_id' => $schoolId,
        'quiz_id' => $snapshot['quiz_id'],
        'quiz_version_id' => $snapshot['version_two_id'],
        'name' => 'Participant Attempt Campaign',
        'status' => 'active',
    ]);
    $campaignId = (int) $connection->lastInsertId();

    $insertBatch = $connection->prepare(
        'INSERT INTO campaign_batches (
            campaign_id,
            batch_number,
            quiz_version_id,
            label,
            status,
            active_marker,
            started_at,
            closed_at,
            created_at,
            updated_at
        ) VALUES (
            :campaign_id,
            :batch_number,
            :quiz_version_id,
            :label,
            :status,
            :active_marker,
            UTC_TIMESTAMP(),
            :closed_at,
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP()
        )'
    );
    $insertBatch->execute([
        'campaign_id' => $campaignId,
        'batch_number' => 1,
        'quiz_version_id' => $snapshot['version_one_id'],
        'label' => 'Historical batch',
        'status' => 'closed',
        'active_marker' => null,
        'closed_at' => '2026-01-01 00:00:00',
    ]);
    $historicalBatchId = (int) $connection->lastInsertId();
    $insertBatch->execute([
        'campaign_id' => $campaignId,
        'batch_number' => 2,
        'quiz_version_id' => $snapshot['version_two_id'],
        'label' => 'Current batch',
        'status' => 'active',
        'active_marker' => 1,
        'closed_at' => null,
    ]);
    $activeBatchId = (int) $connection->lastInsertId();
    participantAttemptAssert($activeBatchId !== $historicalBatchId, 'Distinct campaign batches were not created.');

    $createdAt = new DateTimeImmutable('2026-03-04 08:15:30', new DateTimeZone('Asia/Jakarta'));
    $updatedAt = new DateTimeImmutable('2026-03-04 09:15:30', new DateTimeZone('Asia/Jakarta'));
    $participantOne = $participants->create(
        $schoolId,
        '20000000-0000-4000-8000-000000000001',
        'Participant One',
        'Origin One',
        'Class One',
        '081234567890',
        false,
        null,
        $createdAt,
        $updatedAt,
    );
    participantAttemptAssert(
        $participantOne->schoolId === $schoolId
        && $participantOne->publicUuid === '20000000-0000-4000-8000-000000000001'
        && $participantOne->fullName === 'Participant One'
        && $participantOne->originSchool === 'Origin One'
        && $participantOne->className === 'Class One'
        && $participantOne->phone === '081234567890'
        && $participantOne->marketingConsent === false
        && $participantOne->marketingConsentAt === null
        && $participantOne->createdAt === participantAttemptFormatUtc($createdAt)
        && $participantOne->updatedAt === participantAttemptFormatUtc($updatedAt),
        'Participant creation did not persist exact fields.',
    );

    $participantTwo = $participants->create(
        $schoolId,
        '20000000-0000-4000-8000-000000000002',
        'Participant Two',
        null,
        null,
        '081234567890',
        false,
        null,
        $createdAt,
        $updatedAt,
    );
    participantAttemptAssert(
        $participantTwo->id !== $participantOne->id && $participantTwo->phone === $participantOne->phone,
        'Participant creation deduplicated a shared phone number.',
    );

    $consentAt = new DateTimeImmutable('2026-03-05 09:00:00', new DateTimeZone('Asia/Jakarta'));
    $consentingParticipant = $participants->create(
        $schoolId,
        '20000000-0000-4000-8000-000000000003',
        'Participant Three',
        null,
        null,
        null,
        true,
        $consentAt,
        $createdAt,
        $updatedAt,
    );
    participantAttemptAssert(
        $consentingParticipant->marketingConsent
        && $consentingParticipant->marketingConsentAt === participantAttemptFormatUtc($consentAt),
        'Explicit marketing consent timestamp was not persisted.',
    );
    participantAttemptAssert(
        $participants->findById($participantOne->id)?->id === $participantOne->id
        && $participants->findById(999999) === null,
        'Participant ID lookup was not exact.',
    );
    participantAttemptAssert(
        $participants->findByPublicUuid($participantOne->publicUuid)?->id === $participantOne->id
        && $participants->findByPublicUuid('20000000-0000-4000-8000-999999999999') === null,
        'Participant public UUID lookup was not exact.',
    );
    participantAttemptDatabaseRejected(
        fn() => $participants->create(
            $schoolId,
            $participantOne->publicUuid,
            'Duplicate UUID',
            null,
            null,
            null,
            false,
            null,
            $createdAt,
            $updatedAt,
        ),
        'Duplicate participant public UUID was accepted.',
    );
    participantAttemptInvalid(
        fn() => $participants->create(
            $schoolId,
            '20000000-0000-4000-8000-000000000004',
            'Invalid false consent',
            null,
            null,
            null,
            false,
            $consentAt,
            $createdAt,
            $updatedAt,
        ),
        'False consent with a timestamp was accepted.',
    );
    participantAttemptInvalid(
        fn() => $participants->create(
            $schoolId,
            '20000000-0000-4000-8000-000000000005',
            'Invalid true consent',
            null,
            null,
            null,
            true,
            null,
            $createdAt,
            $updatedAt,
        ),
        'True consent without a timestamp was accepted.',
    );

    $attemptCreatedAt = new DateTimeImmutable('2026-03-06 12:30:00', new DateTimeZone('Asia/Jakarta'));
    $startedAttempt = $attempts->create(
        $participantOne->id,
        $campaignId,
        $historicalBatchId,
        $snapshot['version_one_id'],
        '30000000-0000-4000-8000-000000000001',
        '40000000-0000-4000-8000-000000000001',
        'smart-link',
        $attemptCreatedAt,
    );
    participantAttemptAssert(
        $startedAttempt->participantId === $participantOne->id
        && $startedAttempt->campaignId === $campaignId
        && $startedAttempt->campaignBatchId === $historicalBatchId
        && $startedAttempt->quizVersionId === $snapshot['version_one_id']
        && $startedAttempt->visitorUuid === '30000000-0000-4000-8000-000000000001'
        && $startedAttempt->attemptUuid === '40000000-0000-4000-8000-000000000001'
        && $startedAttempt->source === 'smart-link'
        && $startedAttempt->status === Attempt::STATUS_STARTED
        && $startedAttempt->submittedAt === null
        && $startedAttempt->createdAt === participantAttemptFormatUtc($attemptCreatedAt),
        'New attempt did not preserve explicit bindings or started state.',
    );
    participantAttemptAssert(
        $startedAttempt->campaignBatchId !== $activeBatchId
        && $startedAttempt->quizVersionId !== $snapshot['version_two_id'],
        'Attempt repository guessed the active batch or latest version.',
    );

    $sameVisitorAttempt = $attempts->create(
        $participantTwo->id,
        $campaignId,
        $historicalBatchId,
        $snapshot['version_one_id'],
        $startedAttempt->visitorUuid,
        '40000000-0000-4000-8000-000000000002',
        null,
        $attemptCreatedAt,
    );
    participantAttemptAssert(
        $sameVisitorAttempt->id !== $startedAttempt->id
        && $sameVisitorAttempt->visitorUuid === $startedAttempt->visitorUuid,
        'Visitor UUID was incorrectly treated as unique.',
    );
    participantAttemptDatabaseRejected(
        fn() => $attempts->create(
            $participantTwo->id,
            $campaignId,
            $historicalBatchId,
            $snapshot['version_one_id'],
            '30000000-0000-4000-8000-000000000002',
            $startedAttempt->attemptUuid,
            null,
            $attemptCreatedAt,
        ),
        'Duplicate attempt UUID was accepted.',
    );
    participantAttemptAssert(
        $attempts->findById($startedAttempt->id)?->attemptUuid === $startedAttempt->attemptUuid
        && $attempts->findById(999999) === null,
        'Attempt ID lookup was not exact.',
    );
    participantAttemptAssert(
        $attempts->findByAttemptUuid($startedAttempt->attemptUuid)?->id === $startedAttempt->id
        && $attempts->findByAttemptUuid('40000000-0000-4000-8000-999999999999') === null,
        'Attempt UUID lookup was not exact.',
    );
    participantAttemptLogic(
        fn() => $attempts->findByAttemptUuidForUpdate($startedAttempt->attemptUuid),
        'Attempt FOR UPDATE lookup ran without a caller transaction.',
    );

    $connection->prepare(
        'INSERT INTO attempts (
            participant_id,
            campaign_id,
            campaign_batch_id,
            quiz_version_id,
            visitor_uuid,
            attempt_uuid,
            source,
            status,
            submitted_at,
            created_at
        ) VALUES (
            NULL,
            NULL,
            NULL,
            :quiz_version_id,
            :visitor_uuid,
            :attempt_uuid,
            NULL,
            :status,
            NULL,
            :created_at
        )'
    )->execute([
        'quiz_version_id' => $snapshot['version_one_id'],
        'visitor_uuid' => '30000000-0000-4000-8000-000000000099',
        'attempt_uuid' => '40000000-0000-4000-8000-000000000099',
        'status' => Attempt::STATUS_STARTED,
        'created_at' => participantAttemptFormatUtc($attemptCreatedAt),
    ]);
    $legacyAttempt = $attempts->findByAttemptUuid('40000000-0000-4000-8000-000000000099');
    participantAttemptAssert(
        $legacyAttempt !== null
        && $legacyAttempt->participantId === null
        && $legacyAttempt->campaignId === null
        && $legacyAttempt->campaignBatchId === null
        && $legacyAttempt->status === Attempt::STATUS_STARTED,
        'Legacy nullable attempt bindings did not hydrate.',
    );

    $connection->beginTransaction();
    try {
        $lockedAttempt = $attempts->findByAttemptUuidForUpdate($startedAttempt->attemptUuid);
        participantAttemptAssert(
            $lockedAttempt?->id === $startedAttempt->id && $connection->inTransaction(),
            'Attempt FOR UPDATE did not preserve the caller transaction.',
        );
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }

    $completedAt = new DateTimeImmutable('2026-03-07 10:00:00', new DateTimeZone('Asia/Jakarta'));
    participantAttemptAssert(
        $attempts->markCompleted($startedAttempt->id, $completedAt),
        'Started attempt did not complete.',
    );
    $completedAttempt = $attempts->findById($startedAttempt->id);
    participantAttemptAssert(
        $completedAttempt?->status === Attempt::STATUS_COMPLETED
        && $completedAttempt->submittedAt === participantAttemptFormatUtc($completedAt),
        'Completed attempt did not retain its explicit completion timestamp.',
    );
    participantAttemptAssert(
        !$attempts->markCompleted($startedAttempt->id, new DateTimeImmutable('2026-03-07 11:00:00', new DateTimeZone('Asia/Jakarta')))
        && $attempts->findById($startedAttempt->id)?->submittedAt === participantAttemptFormatUtc($completedAt),
        'Second completion transition mutated an already completed attempt.',
    );

    foreach ([
        ['participant_id' => 999999, 'campaign_id' => $campaignId, 'campaign_batch_id' => $historicalBatchId, 'quiz_version_id' => $snapshot['version_one_id']],
        ['participant_id' => $participantOne->id, 'campaign_id' => 999999, 'campaign_batch_id' => $historicalBatchId, 'quiz_version_id' => $snapshot['version_one_id']],
        ['participant_id' => $participantOne->id, 'campaign_id' => $campaignId, 'campaign_batch_id' => 999999, 'quiz_version_id' => $snapshot['version_one_id']],
        ['participant_id' => $participantOne->id, 'campaign_id' => $campaignId, 'campaign_batch_id' => $historicalBatchId, 'quiz_version_id' => 999999],
    ] as $index => $invalidBindings) {
        participantAttemptDatabaseRejected(
            fn() => $attempts->create(
                $invalidBindings['participant_id'],
                $invalidBindings['campaign_id'],
                $invalidBindings['campaign_batch_id'],
                $invalidBindings['quiz_version_id'],
                sprintf('30000000-0000-4000-8000-%012d', 500 + $index),
                sprintf('40000000-0000-4000-8000-%012d', 500 + $index),
                null,
                $attemptCreatedAt,
            ),
            'Invalid required attempt foreign key was accepted.',
        );
    }

    participantAttemptAssert(
        !method_exists($participants, 'delete') && !method_exists($attempts, 'delete'),
        'Participant or attempt repository exposes a hard delete API.',
    );
    participantAttemptAssert(
        !method_exists($participants, 'findForPublicResult') && !method_exists($attempts, 'findForPublicResult'),
        'Repository exposes a public PII projection API.',
    );

    echo "Participant and attempt repository tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . PARTICIPANT_ATTEMPT_TEST_DATABASE);
    }
}
