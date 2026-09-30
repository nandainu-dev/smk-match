<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

const QUESTION_IMAGE_MIGRATION_DATABASE = 'smk_match_g13_r2a_migration_test';

function migrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function migrationSql(PDO $connection, string $filename): void
{
    $path = SMK_MATCH_ROOT . '/database/migrations/' . $filename;
    $sql = file_get_contents($path);
    migrationAssert($sql !== false, 'Migration could not be read: ' . $filename);

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $connection->exec($statement);
    }
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

migrationAssert(
    $host === '127.0.0.1'
        && $port === '3306'
        && $databaseName === QUESTION_IMAGE_MIGRATION_DATABASE,
    'Unsafe test database configuration.',
);
migrationAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $admin->exec('DROP DATABASE IF EXISTS ' . QUESTION_IMAGE_MIGRATION_DATABASE);
    $admin->exec('CREATE DATABASE ' . QUESTION_IMAGE_MIGRATION_DATABASE . ' CHARACTER SET utf8mb4');

    $connection = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=' . QUESTION_IMAGE_MIGRATION_DATABASE . ';charset=utf8mb4',
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );

    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/0*.sql') ?: [] as $path) {
        if (basename($path) === '012_add_question_image_path.sql') {
            continue;
        }

        $sql = file_get_contents($path);
        migrationAssert($sql !== false, 'Migration could not be read.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }

    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('13000000-0000-4000-8000-000000000001', 'Migration School', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES ({$schoolId}, 'Existing Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $quizId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at) VALUES ({$quizId}, 1, 'draft', 'Existing Version', UTC_TIMESTAMP())");
    $versionId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at) VALUES ({$versionId}, 'Existing question', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $existingQuestionId = (int) $connection->lastInsertId();

    migrationSql($connection, '012_add_question_image_path.sql');

    $column = $connection->prepare(
        'SELECT is_nullable, data_type, character_maximum_length
         FROM information_schema.columns
         WHERE table_schema = :schema_name
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $column->execute([
        'schema_name' => QUESTION_IMAGE_MIGRATION_DATABASE,
        'table_name' => 'questions',
        'column_name' => 'image_path',
    ]);
    $columnData = $column->fetch();
    migrationAssert(
        $columnData !== false
            && $columnData['is_nullable'] === 'YES'
            && $columnData['data_type'] === 'varchar'
            && (int) $columnData['character_maximum_length'] === 500,
        'Question image column does not have the required nullable varchar shape.',
    );

    $existing = $connection->prepare('SELECT prompt, image_path FROM questions WHERE id = :id');
    $existing->execute(['id' => $existingQuestionId]);
    $existingData = $existing->fetch();
    migrationAssert(
        $existingData !== false
            && $existingData['prompt'] === 'Existing question'
            && $existingData['image_path'] === null,
        'Existing question did not survive the additive migration with a null image path.',
    );

    $insert = $connection->prepare(
        'INSERT INTO questions (quiz_version_id, prompt, image_path, sort_order, created_at, updated_at)
         VALUES (:version_id, :prompt, :image_path, :sort_order, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $insert->execute([
        'version_id' => $versionId,
        'prompt' => 'New null-image question',
        'image_path' => null,
        'sort_order' => 2,
    ]);
    migrationAssert((int) $connection->lastInsertId() > 0, 'Null image path was not accepted for a new question.');

    echo "Question image migration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . QUESTION_IMAGE_MIGRATION_DATABASE);
    }

    putenv('DB_PASSWORD');
}
