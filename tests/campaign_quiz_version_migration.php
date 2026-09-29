<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

const TEST_DATABASE = 'smk_match_g10_r1_test';

function migrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectForeignKeyViolation(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @return list<string> */
function statementsFrom(string $path): array
{
    $contents = file_get_contents($path);
    migrationAssert($contents !== false, 'Migration could not be read: ' . basename($path));

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

/** @return array<string, mixed> */
function preMigrationCampaignRow(PDO $connection, int $campaignId): array
{
    $statement = $connection->prepare(
        'SELECT id, school_id, quiz_id, name, status, starts_at, ends_at, created_at, updated_at
         FROM campaigns
         WHERE id = :id'
    );
    $statement->execute(['id' => $campaignId]);
    $row = $statement->fetch();
    migrationAssert(is_array($row), 'Representative campaign was not found.');

    return $row;
}

/** @return array<string, mixed> */
function postMigrationCampaignRow(PDO $connection, int $campaignId): array
{
    $statement = $connection->prepare(
        'SELECT id, school_id, quiz_id, quiz_version_id, name, status, starts_at, ends_at, created_at, updated_at
         FROM campaigns
         WHERE id = :id'
    );
    $statement->execute(['id' => $campaignId]);
    $row = $statement->fetch();
    migrationAssert(is_array($row), 'Representative campaign was not found.');

    return $row;
}

function campaignColumnExists(PDO $connection, string $columnName): bool
{
    $statement = $connection->prepare(
        "SELECT EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'campaigns'
              AND column_name = :column_name
        )"
    );
    $statement->execute(['column_name' => $columnName]);

    return (bool) $statement->fetchColumn();
}

function campaignCount(PDO $connection): int
{
    return (int) $connection->query('SELECT COUNT(*) FROM campaigns')->fetchColumn();
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

migrationAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === TEST_DATABASE,
    'Unsafe test database configuration.',
);
migrationAssert(is_string($user) && $user !== '' && is_string($password), 'Local database credentials are required.');

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
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/00[1-5]_*.sql') ?: [] as $migration) {
        foreach (statementsFrom($migration) as $statement) {
            $connection->exec($statement);
        }
    }
    migrationAssert(
        !campaignColumnExists($connection, 'quiz_version_id'),
        'quiz_version_id must be absent before migration 006.',
    );

    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('11111111-1111-1111-1111-111111111111', 'Migration School', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolId}, 'Migration Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES ({$quizId}, 1, 'published', 'Migration Quiz', UTC_TIMESTAMP())"
    );
    $quizVersionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO campaigns (school_id, quiz_id, name, status, starts_at, ends_at, created_at, updated_at)
         VALUES ({$schoolId}, {$quizId}, 'Preserved campaign', 'draft', '2026-01-01 00:00:00', '2026-01-31 23:59:59', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $campaignId = (int) $connection->lastInsertId();
    $before = preMigrationCampaignRow($connection, $campaignId);
    $campaignCountBefore = campaignCount($connection);

    foreach (statementsFrom(SMK_MATCH_ROOT . '/database/migrations/006_add_campaign_quiz_version.sql') as $statement) {
        $connection->exec($statement);
    }
    migrationAssert(
        campaignColumnExists($connection, 'quiz_version_id'),
        'quiz_version_id must exist after migration 006.',
    );

    $columnStatement = $connection->prepare(
        "SELECT column_type, is_nullable
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'campaigns'
           AND column_name = 'quiz_version_id'"
    );
    $columnStatement->execute();
    $column = $columnStatement->fetch();
    migrationAssert(is_array($column), 'campaigns.quiz_version_id is missing.');
    migrationAssert(strtolower((string) $column['column_type']) === 'bigint(20) unsigned', 'quiz_version_id type is incompatible.');
    migrationAssert($column['is_nullable'] === 'YES', 'quiz_version_id must be nullable.');

    $foreignKeyStatement = $connection->prepare(
        "SELECT kcu.constraint_name, kcu.referenced_table_name, kcu.referenced_column_name, rc.delete_rule
         FROM information_schema.key_column_usage AS kcu
         INNER JOIN information_schema.referential_constraints AS rc
             ON rc.constraint_schema = kcu.constraint_schema
            AND rc.constraint_name = kcu.constraint_name
            AND rc.table_name = kcu.table_name
         WHERE kcu.table_schema = DATABASE()
           AND kcu.table_name = 'campaigns'
           AND kcu.column_name = 'quiz_version_id'"
    );
    $foreignKeyStatement->execute();
    $foreignKey = $foreignKeyStatement->fetch();
    migrationAssert(is_array($foreignKey), 'quiz_version_id foreign key is missing.');
    migrationAssert($foreignKey['constraint_name'] === 'campaigns_quiz_version_fk', 'Unexpected quiz version foreign key name.');
    migrationAssert($foreignKey['referenced_table_name'] === 'quiz_versions', 'Foreign key targets the wrong table.');
    migrationAssert($foreignKey['referenced_column_name'] === 'id', 'Foreign key targets the wrong column.');
    migrationAssert($foreignKey['delete_rule'] === 'RESTRICT', 'Foreign key delete rule must be RESTRICT.');

    $indexStatement = $connection->prepare(
        "SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'campaigns'
           AND column_name = 'quiz_version_id'"
    );
    $indexStatement->execute();
    migrationAssert((int) $indexStatement->fetchColumn() > 0, 'quiz_version_id is not indexed.');

    $after = postMigrationCampaignRow($connection, $campaignId);
    foreach (['id', 'school_id', 'quiz_id', 'name', 'status', 'starts_at', 'ends_at', 'created_at', 'updated_at'] as $field) {
        migrationAssert($after[$field] === $before[$field], 'Migration rewrote campaign field: ' . $field);
    }
    migrationAssert(campaignCount($connection) === $campaignCountBefore, 'Migration changed campaign row count.');
    migrationAssert($after['quiz_version_id'] === null, 'Existing campaign must remain unconfigured.');

    $connection->exec("UPDATE campaigns SET quiz_version_id = {$quizVersionId} WHERE id = {$campaignId}");
    migrationAssert((int) postMigrationCampaignRow($connection, $campaignId)['quiz_version_id'] === $quizVersionId, 'Valid quiz version assignment failed.');

    expectForeignKeyViolation(
        static fn () => $connection->exec("UPDATE campaigns SET quiz_version_id = 999999 WHERE id = {$campaignId}"),
        'Invalid quiz version assignment was accepted.',
    );
    expectForeignKeyViolation(
        static fn () => $connection->exec("DELETE FROM quiz_versions WHERE id = {$quizVersionId}"),
        'Referenced quiz version deletion was accepted.',
    );

    $connection->exec("UPDATE campaigns SET quiz_version_id = NULL WHERE id = {$campaignId}");
    migrationAssert(postMigrationCampaignRow($connection, $campaignId)['quiz_version_id'] === null, 'quiz_version_id could not be set back to NULL.');

    echo "Campaign quiz version migration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    }
}
