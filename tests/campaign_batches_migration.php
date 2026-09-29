<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

const TEST_DATABASE = 'smk_match_g10_r2_test';

function batchMigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectBatchForeignKeyOrUniqueViolation(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @return list<string> */
function batchMigrationStatements(string $path): array
{
    $contents = file_get_contents($path);
    batchMigrationAssert($contents !== false, 'Migration could not be read: ' . basename($path));

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

function batchTableExists(PDO $connection): bool
{
    $statement = $connection->prepare(
        "SELECT EXISTS(
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'campaign_batches'
        )"
    );
    $statement->execute();

    return (bool) $statement->fetchColumn();
}

/** @return array<string, array<string, mixed>> */
function batchColumns(PDO $connection): array
{
    $statement = $connection->prepare(
        "SELECT column_name, column_type, is_nullable
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'campaign_batches'"
    );
    $statement->execute();
    $columns = [];

    foreach ($statement->fetchAll() as $column) {
        $columns[(string) $column['column_name']] = $column;
    }

    return $columns;
}

/** @return array<string, array<string, mixed>> */
function batchForeignKeys(PDO $connection): array
{
    $statement = $connection->prepare(
        "SELECT kcu.constraint_name, kcu.column_name, kcu.referenced_table_name, kcu.referenced_column_name, rc.delete_rule
         FROM information_schema.key_column_usage AS kcu
         INNER JOIN information_schema.referential_constraints AS rc
             ON rc.constraint_schema = kcu.constraint_schema
            AND rc.constraint_name = kcu.constraint_name
            AND rc.table_name = kcu.table_name
         WHERE kcu.table_schema = DATABASE()
           AND kcu.table_name = 'campaign_batches'
           AND kcu.referenced_table_name IS NOT NULL"
    );
    $statement->execute();
    $foreignKeys = [];

    foreach ($statement->fetchAll() as $foreignKey) {
        $foreignKeys[(string) $foreignKey['column_name']] = $foreignKey;
    }

    return $foreignKeys;
}

function createBatch(
    PDO $connection,
    int $campaignId,
    int $batchNumber,
    int $quizVersionId,
    ?string $label,
    string $status,
    ?int $activeMarker,
    ?string $closedAt = null,
): int {
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
            :closed_at,
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP()
        )'
    );
    $statement->execute([
        'campaign_id' => $campaignId,
        'batch_number' => $batchNumber,
        'quiz_version_id' => $quizVersionId,
        'label' => $label,
        'status' => $status,
        'active_marker' => $activeMarker,
        'closed_at' => $closedAt,
    ]);

    return (int) $connection->lastInsertId();
}

/** @return array<string, mixed> */
function batchRow(PDO $connection, int $batchId): array
{
    $statement = $connection->prepare(
        'SELECT id, campaign_id, batch_number, quiz_version_id, label, status, active_marker, started_at, closed_at, created_at, updated_at
         FROM campaign_batches
         WHERE id = :id'
    );
    $statement->execute(['id' => $batchId]);
    $row = $statement->fetch();
    batchMigrationAssert(is_array($row), 'Expected batch was not found.');

    return $row;
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

batchMigrationAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === TEST_DATABASE,
    'Unsafe test database configuration.',
);
batchMigrationAssert(is_string($user) && $user !== '' && is_string($password), 'Local database credentials are required.');

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
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/00[1-6]_*.sql') ?: [] as $migration) {
        foreach (batchMigrationStatements($migration) as $statement) {
            $connection->exec($statement);
        }
    }
    batchMigrationAssert(!batchTableExists($connection), 'campaign_batches must be absent before migration 007.');

    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('22222222-2222-2222-2222-222222222222', 'Batch School A', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolA = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('33333333-3333-3333-3333-333333333333', 'Batch School B', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolB = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolA}, 'Batch Quiz A', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizA = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolB}, 'Batch Quiz B', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizB = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES ({$quizA}, 1, 'published', 'Batch Quiz A', UTC_TIMESTAMP())"
    );
    $versionA = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES ({$quizB}, 1, 'published', 'Batch Quiz B', UTC_TIMESTAMP())"
    );
    $versionB = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at)
         VALUES ({$schoolA}, {$quizA}, {$versionA}, 'Batch Campaign A', 'draft', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $campaignA = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at)
         VALUES ({$schoolB}, {$quizB}, {$versionB}, 'Batch Campaign B', 'draft', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $campaignB = (int) $connection->lastInsertId();

    foreach (batchMigrationStatements(SMK_MATCH_ROOT . '/database/migrations/007_create_campaign_batches.sql') as $statement) {
        $connection->exec($statement);
    }
    batchMigrationAssert(batchTableExists($connection), 'campaign_batches must exist after migration 007.');

    $columns = batchColumns($connection);
    foreach (['id', 'campaign_id', 'batch_number', 'quiz_version_id', 'label', 'status', 'active_marker', 'started_at', 'closed_at', 'created_at', 'updated_at'] as $columnName) {
        batchMigrationAssert(isset($columns[$columnName]), 'Required batch column is missing: ' . $columnName);
    }
    batchMigrationAssert($columns['campaign_id']['is_nullable'] === 'NO', 'campaign_id must be NOT NULL.');
    batchMigrationAssert($columns['batch_number']['is_nullable'] === 'NO', 'batch_number must be NOT NULL.');
    batchMigrationAssert($columns['quiz_version_id']['is_nullable'] === 'NO', 'quiz_version_id must be NOT NULL.');
    batchMigrationAssert($columns['status']['is_nullable'] === 'NO', 'status must be NOT NULL.');
    batchMigrationAssert($columns['active_marker']['is_nullable'] === 'YES', 'active_marker must be nullable.');
    batchMigrationAssert($columns['started_at']['is_nullable'] === 'NO', 'started_at must be NOT NULL.');
    batchMigrationAssert($columns['closed_at']['is_nullable'] === 'YES', 'closed_at must be nullable.');

    $foreignKeys = batchForeignKeys($connection);
    batchMigrationAssert(
        isset($foreignKeys['campaign_id'])
            && $foreignKeys['campaign_id']['constraint_name'] === 'campaign_batches_campaign_fk'
            && $foreignKeys['campaign_id']['referenced_table_name'] === 'campaigns'
            && $foreignKeys['campaign_id']['referenced_column_name'] === 'id'
            && $foreignKeys['campaign_id']['delete_rule'] === 'RESTRICT',
        'campaign_batches campaign foreign key is incorrect.',
    );
    batchMigrationAssert(
        isset($foreignKeys['quiz_version_id'])
            && $foreignKeys['quiz_version_id']['constraint_name'] === 'campaign_batches_quiz_version_fk'
            && $foreignKeys['quiz_version_id']['referenced_table_name'] === 'quiz_versions'
            && $foreignKeys['quiz_version_id']['referenced_column_name'] === 'id'
            && $foreignKeys['quiz_version_id']['delete_rule'] === 'RESTRICT',
        'campaign_batches quiz version foreign key is incorrect.',
    );
    batchMigrationAssert((int) $connection->query('SELECT COUNT(*) FROM campaign_batches')->fetchColumn() === 0, 'Migration 007 must not auto-create batches.');

    $batchOne = createBatch($connection, $campaignA, 1, $versionA, 'Batch 1', 'active', 1);
    $batchOneRow = batchRow($connection, $batchOne);
    batchMigrationAssert(
        (int) $batchOneRow['quiz_version_id'] === $versionA && $batchOneRow['status'] === 'active' && (int) $batchOneRow['active_marker'] === 1,
        'Valid active batch did not preserve its quiz version snapshot.',
    );

    expectBatchForeignKeyOrUniqueViolation(
        static fn () => createBatch($connection, $campaignA, 1, $versionA, 'Duplicate number', 'closed', null, '2026-01-01 01:00:00'),
        'Duplicate batch number for one campaign was accepted.',
    );
    expectBatchForeignKeyOrUniqueViolation(
        static fn () => createBatch($connection, $campaignA, 2, $versionA, 'Second active', 'active', 1),
        'Second active batch for one campaign was accepted.',
    );
    $otherCampaignBatchOne = createBatch($connection, $campaignB, 1, $versionB, 'Other campaign batch 1', 'active', 1);
    batchMigrationAssert(
        (int) batchRow($connection, $otherCampaignBatchOne)['batch_number'] === 1,
        'The same batch number must be allowed for a different campaign.',
    );

    $connection->exec(
        "UPDATE campaign_batches
         SET status = 'closed', active_marker = NULL, closed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
         WHERE id = {$batchOne}"
    );
    $closedBatchTwo = createBatch($connection, $campaignA, 2, $versionA, 'Batch 2', 'closed', null, '2026-01-01 02:00:00');
    $activeBatchThree = createBatch($connection, $campaignA, 3, $versionA, null, 'active', 1);
    $closedBatchOne = batchRow($connection, $batchOne);
    batchMigrationAssert(
        $closedBatchOne['status'] === 'closed'
            && $closedBatchOne['active_marker'] === null
            && $closedBatchOne['closed_at'] !== null
            && (int) $closedBatchOne['quiz_version_id'] === $versionA
            && (int) $connection->query('SELECT COUNT(*) FROM campaign_batches WHERE campaign_id = ' . $campaignA . ' AND status = \'closed\'')->fetchColumn() === 2,
        'Closed batch history was not preserved.',
    );
    batchMigrationAssert(batchRow($connection, $closedBatchTwo)['active_marker'] === null, 'Closed batch two must allow a NULL active marker.');
    batchMigrationAssert((int) batchRow($connection, $activeBatchThree)['active_marker'] === 1, 'Batch three must be active.');
    expectBatchForeignKeyOrUniqueViolation(
        static fn () => createBatch($connection, $campaignA, 4, $versionA, 'Fourth active', 'active', 1),
        'A second active marker for one campaign was accepted.',
    );
    expectBatchForeignKeyOrUniqueViolation(
        static fn () => createBatch($connection, 999999, 1, $versionA, null, 'closed', null, '2026-01-01 03:00:00'),
        'Invalid campaign foreign key was accepted.',
    );
    expectBatchForeignKeyOrUniqueViolation(
        static fn () => createBatch($connection, $campaignB, 2, 999999, null, 'closed', null, '2026-01-01 03:00:00'),
        'Invalid quiz version foreign key was accepted.',
    );

    expectBatchForeignKeyOrUniqueViolation(
        static fn () => $connection->exec("DELETE FROM campaigns WHERE id = {$campaignA}"),
        'Referenced campaign deletion was accepted.',
    );
    $connection->exec("UPDATE campaigns SET quiz_version_id = NULL WHERE id = {$campaignA}");
    expectBatchForeignKeyOrUniqueViolation(
        static fn () => $connection->exec("DELETE FROM quiz_versions WHERE id = {$versionA}"),
        'Referenced quiz version deletion was accepted.',
    );

    $programColumnStatement = $connection->prepare(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'campaign_batches'
           AND column_name LIKE '%program%'"
    );
    $programColumnStatement->execute();
    batchMigrationAssert((int) $programColumnStatement->fetchColumn() === 0, 'campaign_batches must not contain program-specific columns.');

    echo "Campaign batches migration integration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    }
}
