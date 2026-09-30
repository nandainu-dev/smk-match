<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

const TEST_DATABASE = 'smk_match_g11_r2_test';

function attemptMigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectAttemptMigrationForeignKeyViolation(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @return list<string> */
function attemptMigrationStatements(string $path): array
{
    $contents = file_get_contents($path);
    attemptMigrationAssert($contents !== false, 'Migration could not be read: ' . basename($path));

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

/** @return array<string, array<string, mixed>> */
function attemptMigrationColumns(PDO $connection, string $table): array
{
    $statement = $connection->prepare(
        'SELECT column_name, column_type, is_nullable, column_default
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
         ORDER BY ordinal_position ASC'
    );
    $statement->execute(['table_name' => $table]);
    $columns = [];

    foreach ($statement->fetchAll() as $column) {
        $columns[(string) $column['column_name']] = $column;
    }

    return $columns;
}

/** @return array<string, array{non_unique: int, columns: list<string>}> */
function attemptMigrationIndexes(PDO $connection): array
{
    $statement = $connection->prepare(
        'SELECT index_name, non_unique, seq_in_index, column_name
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
         ORDER BY index_name ASC, seq_in_index ASC'
    );
    $statement->execute(['table_name' => 'attempts']);
    $indexes = [];

    foreach ($statement->fetchAll() as $index) {
        $name = (string) $index['index_name'];
        $indexes[$name] ??= [
            'non_unique' => (int) $index['non_unique'],
            'columns' => [],
        ];
        $indexes[$name]['columns'][] = (string) $index['column_name'];
    }

    return $indexes;
}

/** @return array<string, mixed> */
function attemptMigrationForeignKey(PDO $connection): array
{
    $statement = $connection->prepare(
        "SELECT kcu.constraint_name, kcu.referenced_table_name, kcu.referenced_column_name, rc.delete_rule
         FROM information_schema.key_column_usage AS kcu
         INNER JOIN information_schema.referential_constraints AS rc
             ON rc.constraint_schema = kcu.constraint_schema
            AND rc.constraint_name = kcu.constraint_name
            AND rc.table_name = kcu.table_name
         WHERE kcu.table_schema = DATABASE()
           AND kcu.table_name = 'attempts'
           AND kcu.column_name = 'campaign_batch_id'"
    );
    $statement->execute();
    $foreignKey = $statement->fetch();
    attemptMigrationAssert(is_array($foreignKey), 'attempts.campaign_batch_id foreign key is missing.');

    return $foreignKey;
}

/** @return array<string, mixed> */
function attemptMigrationRow(PDO $connection, int $attemptId): array
{
    $statement = $connection->prepare(
        'SELECT id, participant_id, campaign_id, campaign_batch_id, quiz_version_id, visitor_uuid, attempt_uuid, source, status, submitted_at, created_at
         FROM attempts
         WHERE id = :id'
    );
    $statement->execute(['id' => $attemptId]);
    $row = $statement->fetch();
    attemptMigrationAssert(is_array($row), 'Expected attempt was not found.');

    return $row;
}

function createAttemptBatch(PDO $connection, int $campaignId, int $quizVersionId, int $number, string $status, ?int $activeMarker): int
{
    $statement = $connection->prepare(
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
            NULL,
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP()
        )'
    );
    $statement->execute([
        'campaign_id' => $campaignId,
        'batch_number' => $number,
        'quiz_version_id' => $quizVersionId,
        'label' => 'Attempt migration batch ' . $number,
        'status' => $status,
        'active_marker' => $activeMarker,
    ]);

    return (int) $connection->lastInsertId();
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

attemptMigrationAssert(
    $host === '127.0.0.1'
        && $port === '3306'
        && $databaseName === TEST_DATABASE,
    'Unsafe test database configuration.',
);
attemptMigrationAssert(
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

    $connection = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=' . TEST_DATABASE . ';charset=utf8mb4',
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $baseMigrations = [
        '001_create_database_foundation.sql',
        '002_add_school_profile_branding_fields.sql',
        '003_add_quiz_version_name_snapshot.sql',
        '004_create_quiz_version_programs.sql',
        '005_change_option_weight_to_double.sql',
        '006_add_campaign_quiz_version.sql',
        '007_create_campaign_batches.sql',
    ];
    foreach ($baseMigrations as $migrationName) {
        foreach (attemptMigrationStatements(SMK_MATCH_ROOT . '/database/migrations/' . $migrationName) as $statement) {
            $connection->exec($statement);
        }
    }

    $attemptColumnsBefore = attemptMigrationColumns($connection, 'attempts');
    attemptMigrationAssert(!isset($attemptColumnsBefore['campaign_batch_id']), 'campaign_batch_id must be absent before migration 008.');
    attemptMigrationAssert(!isset($attemptColumnsBefore['status']), 'status must be absent before migration 008.');
    $participantColumnsBefore = attemptMigrationColumns($connection, 'participants');
    $resultColumnsBefore = attemptMigrationColumns($connection, 'results');
    $resultScoreColumnsBefore = attemptMigrationColumns($connection, 'result_scores');

    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('88888888-8888-4888-8888-888888888888', 'Attempt Migration School', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO participants (school_id, public_uuid, full_name, marketing_consent, created_at, updated_at)
         VALUES ({$schoolId}, '99999999-9999-4999-8999-999999999999', 'Legacy Participant', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $participantId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolId}, 'Attempt Migration Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES ({$quizId}, 1, 'published', 'Attempt Migration Version', UTC_TIMESTAMP())"
    );
    $quizVersionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at)
         VALUES ({$schoolId}, {$quizId}, {$quizVersionId}, 'Attempt Migration Campaign', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $campaignId = (int) $connection->lastInsertId();

    $legacyInsert = $connection->prepare(
        'INSERT INTO attempts (
            participant_id,
            campaign_id,
            quiz_version_id,
            visitor_uuid,
            attempt_uuid,
            source,
            submitted_at,
            created_at
        ) VALUES (
            :participant_id,
            :campaign_id,
            :quiz_version_id,
            :visitor_uuid,
            :attempt_uuid,
            :source,
            :submitted_at,
            :created_at
        )'
    );
    $legacyInsert->execute([
        'participant_id' => $participantId,
        'campaign_id' => $campaignId,
        'quiz_version_id' => $quizVersionId,
        'visitor_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'attempt_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'source' => 'legacy-started',
        'submitted_at' => null,
        'created_at' => '2026-01-01 01:02:03',
    ]);
    $legacyStartedId = (int) $connection->lastInsertId();
    $legacyInsert->execute([
        'participant_id' => $participantId,
        'campaign_id' => $campaignId,
        'quiz_version_id' => $quizVersionId,
        'visitor_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'attempt_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        'source' => 'legacy-completed',
        'submitted_at' => '2026-01-01 04:05:06',
        'created_at' => '2026-01-01 02:03:04',
    ]);
    $legacyCompletedId = (int) $connection->lastInsertId();

    $legacyStartedBefore = attemptMigrationRowBefore($connection, $legacyStartedId);
    $legacyCompletedBefore = attemptMigrationRowBefore($connection, $legacyCompletedId);

    foreach (attemptMigrationStatements(SMK_MATCH_ROOT . '/database/migrations/008_add_attempt_batch_lifecycle.sql') as $statement) {
        $connection->exec($statement);
    }

    $attemptColumns = attemptMigrationColumns($connection, 'attempts');
    attemptMigrationAssert(isset($attemptColumns['campaign_batch_id']), 'campaign_batch_id is missing after migration 008.');
    attemptMigrationAssert($attemptColumns['campaign_batch_id']['is_nullable'] === 'YES', 'campaign_batch_id must remain nullable.');
    attemptMigrationAssert(isset($attemptColumns['status']), 'status is missing after migration 008.');
    attemptMigrationAssert($attemptColumns['status']['is_nullable'] === 'NO', 'status must be NOT NULL.');
    attemptMigrationAssert($attemptColumns['status']['column_default'] === null, 'status must have no default.');
    attemptMigrationAssert($attemptColumns['campaign_id']['is_nullable'] === 'YES', 'campaign_id nullability must be preserved.');
    attemptMigrationAssert($attemptColumns['quiz_version_id']['is_nullable'] === 'NO', 'quiz_version_id must remain NOT NULL.');
    attemptMigrationAssert(!isset($attemptColumns['started_at']), 'started_at must not be added.');
    attemptMigrationAssert(!isset($attemptColumns['completed_at']), 'completed_at must not be added.');
    attemptMigrationAssert(isset($attemptColumns['created_at']), 'created_at must remain present.');
    attemptMigrationAssert(isset($attemptColumns['submitted_at']), 'submitted_at must remain present.');

    $foreignKey = attemptMigrationForeignKey($connection);
    attemptMigrationAssert(
        $foreignKey['constraint_name'] === 'attempts_campaign_batch_fk'
            && $foreignKey['referenced_table_name'] === 'campaign_batches'
            && $foreignKey['referenced_column_name'] === 'id'
            && $foreignKey['delete_rule'] === 'RESTRICT',
        'campaign_batch_id foreign key is incorrect.',
    );

    $legacyStarted = attemptMigrationRow($connection, $legacyStartedId);
    $legacyCompleted = attemptMigrationRow($connection, $legacyCompletedId);
    attemptMigrationAssert($legacyStarted['status'] === 'started', 'Legacy unsubmitted attempt was not backfilled as started.');
    attemptMigrationAssert($legacyCompleted['status'] === 'completed', 'Legacy submitted attempt was not backfilled as completed.');
    attemptMigrationAssert($legacyStarted['campaign_batch_id'] === null && $legacyCompleted['campaign_batch_id'] === null, 'Legacy attempt batch binding was guessed.');
    foreach (['id', 'participant_id', 'campaign_id', 'quiz_version_id', 'visitor_uuid', 'attempt_uuid', 'source', 'submitted_at', 'created_at'] as $column) {
        attemptMigrationAssert($legacyStarted[$column] === $legacyStartedBefore[$column], 'Legacy started value changed: ' . $column);
        attemptMigrationAssert($legacyCompleted[$column] === $legacyCompletedBefore[$column], 'Legacy completed value changed: ' . $column);
    }

    $batchOneId = createAttemptBatch($connection, $campaignId, $quizVersionId, 1, 'active', 1);
    $postMigrationInsert = $connection->prepare(
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
            :participant_id,
            :campaign_id,
            :campaign_batch_id,
            :quiz_version_id,
            :visitor_uuid,
            :attempt_uuid,
            :source,
            :status,
            :submitted_at,
            :created_at
        )'
    );
    $postMigrationInsert->execute([
        'participant_id' => $participantId,
        'campaign_id' => $campaignId,
        'campaign_batch_id' => $batchOneId,
        'quiz_version_id' => $quizVersionId,
        'visitor_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        'attempt_uuid' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
        'source' => 'post-migration-started',
        'status' => 'started',
        'submitted_at' => null,
        'created_at' => '2026-01-01 05:06:07',
    ]);
    $postMigrationStartedId = (int) $connection->lastInsertId();
    attemptMigrationAssert(
        (int) attemptMigrationRow($connection, $postMigrationStartedId)['campaign_batch_id'] === $batchOneId,
        'Explicit post-migration attempt did not retain its batch binding.',
    );
    $postMigrationInsert->execute([
        'participant_id' => $participantId,
        'campaign_id' => $campaignId,
        'campaign_batch_id' => $batchOneId,
        'quiz_version_id' => $quizVersionId,
        'visitor_uuid' => '11111111-1111-4111-8111-111111111111',
        'attempt_uuid' => '22222222-2222-4222-8222-222222222222',
        'source' => 'post-migration-completed',
        'status' => 'completed',
        'submitted_at' => '2026-01-01 06:07:08',
        'created_at' => '2026-01-01 05:07:08',
    ]);
    $postMigrationCompletedId = (int) $connection->lastInsertId();
    $postMigrationCompleted = attemptMigrationRow($connection, $postMigrationCompletedId);
    attemptMigrationAssert(
        $postMigrationCompleted['status'] === 'completed'
            && $postMigrationCompleted['submitted_at'] === '2026-01-01 06:07:08',
        'Explicit completed attempt was not persisted with its submission timestamp.',
    );

    expectAttemptMigrationForeignKeyViolation(
        static fn () => $postMigrationInsert->execute([
            'participant_id' => $participantId,
            'campaign_id' => $campaignId,
            'campaign_batch_id' => 999999,
            'quiz_version_id' => $quizVersionId,
            'visitor_uuid' => '33333333-3333-4333-8333-333333333333',
            'attempt_uuid' => '44444444-4444-4444-8444-444444444444',
            'source' => 'invalid-batch',
            'status' => 'started',
            'submitted_at' => null,
            'created_at' => '2026-01-01 07:08:09',
        ]),
        'Invalid campaign_batch_id was accepted.',
    );

    $indexes = attemptMigrationIndexes($connection);
    foreach ([
        'attempts_batch_status_submitted' => ['non_unique' => 1, 'columns' => ['campaign_batch_id', 'status', 'submitted_at']],
        'attempts_visitor_created' => ['non_unique' => 1, 'columns' => ['visitor_uuid', 'created_at']],
        'attempt_uuid' => ['non_unique' => 0, 'columns' => ['attempt_uuid']],
        'attempts_version_submitted' => ['non_unique' => 1, 'columns' => ['quiz_version_id', 'submitted_at']],
        'attempts_participant_created' => ['non_unique' => 1, 'columns' => ['participant_id', 'created_at']],
    ] as $name => $expected) {
        attemptMigrationAssert(isset($indexes[$name]), 'Required attempts index is missing: ' . $name);
        attemptMigrationAssert($indexes[$name] === $expected, 'Unexpected attempts index definition: ' . $name);
    }
    attemptMigrationAssert(!isset($indexes['visitor_uuid']), 'visitor_uuid must not be unique.');
    attemptMigrationAssert(!isset($indexes['attempts_campaign_batch_submitted']), 'Redundant campaign/batch index was added.');

    expectAttemptMigrationForeignKeyViolation(
        static fn () => $connection->exec("DELETE FROM campaign_batches WHERE id = {$batchOneId}"),
        'Referenced campaign batch deletion was accepted.',
    );
    $connection->exec(
        "UPDATE campaign_batches
         SET status = 'closed', active_marker = NULL, closed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
         WHERE id = {$batchOneId}"
    );
    $batchTwoId = createAttemptBatch($connection, $campaignId, $quizVersionId, 2, 'active', 1);
    attemptMigrationAssert(
        (int) attemptMigrationRow($connection, $postMigrationStartedId)['campaign_batch_id'] === $batchOneId
            && $batchTwoId !== $batchOneId,
        'Closing a batch and opening another changed historical attempt binding.',
    );

    attemptMigrationAssert(
        attemptMigrationColumns($connection, 'participants') === $participantColumnsBefore,
        'Migration 008 changed participant schema.',
    );
    attemptMigrationAssert(
        attemptMigrationColumns($connection, 'results') === $resultColumnsBefore
            && attemptMigrationColumns($connection, 'result_scores') === $resultScoreColumnsBefore,
        'Migration 008 changed result schema.',
    );

    echo "Attempt batch lifecycle migration integration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    }
}

/** @return array<string, mixed> */
function attemptMigrationRowBefore(PDO $connection, int $attemptId): array
{
    $statement = $connection->prepare(
        'SELECT id, participant_id, campaign_id, quiz_version_id, visitor_uuid, attempt_uuid, source, submitted_at, created_at
         FROM attempts
         WHERE id = :id'
    );
    $statement->execute(['id' => $attemptId]);
    $row = $statement->fetch();
    attemptMigrationAssert(is_array($row), 'Expected legacy attempt was not found.');

    return $row;
}
