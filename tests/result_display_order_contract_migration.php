<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

const RESULT_DISPLAY_ORDER_CONTRACT_TEST_DATABASE = 'smk_match_g11_r12a3_test';

/** @var list<string> */
const RESULT_DISPLAY_ORDER_PRE_DISPLAY_ORDER_MIGRATIONS = [
    '001_create_database_foundation.sql',
    '002_add_school_profile_branding_fields.sql',
    '003_add_quiz_version_name_snapshot.sql',
    '004_create_quiz_version_programs.sql',
    '005_change_option_weight_to_double.sql',
    '006_add_campaign_quiz_version.sql',
    '007_create_campaign_batches.sql',
    '008_add_attempt_batch_lifecycle.sql',
    '009_add_result_tie_precision.sql',
];

function resultDisplayOrderAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function resultDisplayOrderExpectDatabaseRejection(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @return list<string> */
function resultDisplayOrderMigrationStatements(string $migrationName): array
{
    $path = SMK_MATCH_ROOT . '/database/migrations/' . $migrationName;
    $contents = file_get_contents($path);
    resultDisplayOrderAssert($contents !== false, 'Migration could not be read: ' . $migrationName);

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

/** @param list<string> $migrationNames */
function resultDisplayOrderApplyMigrations(PDO $connection, array $migrationNames): void
{
    foreach ($migrationNames as $migrationName) {
        foreach (resultDisplayOrderMigrationStatements($migrationName) as $statement) {
            $connection->exec($statement);
        }
    }
}

function resultDisplayOrderCreateDatabase(PDO $admin, string $username, string $password): PDO
{
    $admin->exec('DROP DATABASE IF EXISTS ' . RESULT_DISPLAY_ORDER_CONTRACT_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . RESULT_DISPLAY_ORDER_CONTRACT_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    return new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=' . RESULT_DISPLAY_ORDER_CONTRACT_TEST_DATABASE . ';charset=utf8mb4',
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
}

/** @return array{program_ids: array<string, int>, result_ids: array<string, int>, score_ids: array<string, list<int>>} */
function resultDisplayOrderCreateLegacyFixture(PDO $connection): array
{
    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('a1111111-1111-4111-8111-111111111111', 'Display Order Contract School', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
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

    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolId}, 'Display Order Contract Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES ({$quizId}, 1, 'published', 'Display Order Contract Version', UTC_TIMESTAMP())"
    );
    $quizVersionId = (int) $connection->lastInsertId();

    $attemptIds = [];
    $insertAttempt = $connection->prepare(
        'INSERT INTO attempts (
            quiz_version_id,
            visitor_uuid,
            attempt_uuid,
            source,
            status,
            created_at
        ) VALUES (
            :quiz_version_id,
            :visitor_uuid,
            :attempt_uuid,
            :source,
            :status,
            UTC_TIMESTAMP()
        )'
    );
    foreach (['first', 'second', 'third'] as $index => $attemptKey) {
        $suffix = (string) ($index + 1);
        $insertAttempt->execute([
            'quiz_version_id' => $quizVersionId,
            'visitor_uuid' => 'a2222222-2222-4222-8222-22222222222' . $suffix,
            'attempt_uuid' => 'a3333333-3333-4333-8333-33333333333' . $suffix,
            'source' => 'migration-contract',
            'status' => 'completed',
        ]);
        $attemptIds[$attemptKey] = (int) $connection->lastInsertId();
    }

    $resultIds = [];
    $insertResult = $connection->prepare(
        'INSERT INTO results (
            attempt_id,
            dominant_program_id,
            total_raw_score,
            is_tie,
            created_at
        ) VALUES (
            :attempt_id,
            :dominant_program_id,
            :total_raw_score,
            :is_tie,
            UTC_TIMESTAMP()
        )'
    );
    foreach (['first' => 3.0, 'second' => 1.0, 'third' => 0.0] as $resultKey => $totalRawScore) {
        $insertResult->execute([
            'attempt_id' => $attemptIds[$resultKey],
            'dominant_program_id' => $programIds['ALPHA'],
            'total_raw_score' => $totalRawScore,
            'is_tie' => 0,
        ]);
        $resultIds[$resultKey] = (int) $connection->lastInsertId();
    }

    $scoreIds = ['first' => [], 'second' => [], 'third' => []];
    $insertScore = $connection->prepare(
        'INSERT INTO result_scores (
            result_id,
            program_id,
            raw_score,
            normalized_percentage,
            created_at
        ) VALUES (
            :result_id,
            :program_id,
            :raw_score,
            :normalized_percentage,
            UTC_TIMESTAMP()
        )'
    );
    foreach (['GAMMA', 'ALPHA', 'BETA'] as $programCode) {
        $insertScore->execute([
            'result_id' => $resultIds['first'],
            'program_id' => $programIds[$programCode],
            'raw_score' => 1.0,
            'normalized_percentage' => 33.333333333333,
        ]);
        $scoreIds['first'][] = (int) $connection->lastInsertId();
    }
    $insertScore->execute([
        'result_id' => $resultIds['second'],
        'program_id' => $programIds['ALPHA'],
        'raw_score' => 1.0,
        'normalized_percentage' => 100.0,
    ]);
    $scoreIds['second'][] = (int) $connection->lastInsertId();

    return [
        'program_ids' => $programIds,
        'result_ids' => $resultIds,
        'score_ids' => $scoreIds,
    ];
}

/** @return list<array<string, mixed>> */
function resultDisplayOrderRows(PDO $connection): array
{
    return $connection->query(
        'SELECT id, result_id, program_id, raw_score, normalized_percentage, display_order, created_at
         FROM result_scores
         ORDER BY result_id ASC, display_order ASC, id ASC'
    )->fetchAll();
}

/** @return array<string, array<string, mixed>> */
function resultDisplayOrderColumns(PDO $connection): array
{
    $statement = $connection->prepare(
        'SELECT column_name, column_type, is_nullable
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name'
    );
    $statement->execute(['table_name' => 'result_scores']);
    $columns = [];

    foreach ($statement->fetchAll() as $column) {
        $columns[(string) $column['column_name']] = $column;
    }

    return $columns;
}

/** @return array<string, array{non_unique: int, columns: list<string>}> */
function resultDisplayOrderIndexes(PDO $connection): array
{
    $statement = $connection->prepare(
        'SELECT index_name, non_unique, seq_in_index, column_name
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
         ORDER BY index_name ASC, seq_in_index ASC'
    );
    $statement->execute(['table_name' => 'result_scores']);
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

function resultDisplayOrderAssertLegacyPreflight(PDO $connection): void
{
    $nullCount = (int) $connection->query(
        'SELECT COUNT(*) FROM result_scores WHERE display_order IS NULL'
    )->fetchColumn();
    resultDisplayOrderAssert($nullCount === 0, 'Migration 010 left null display orders.');

    $nonPositiveCount = (int) $connection->query(
        'SELECT COUNT(*) FROM result_scores WHERE display_order < 1'
    )->fetchColumn();
    resultDisplayOrderAssert($nonPositiveCount === 0, 'Migration 010 left non-positive display orders.');

    $duplicateGroups = $connection->query(
        'SELECT result_id, display_order
         FROM result_scores
         GROUP BY result_id, display_order
         HAVING COUNT(*) > 1'
    )->fetchAll();
    resultDisplayOrderAssert($duplicateGroups === [], 'Migration 010 left duplicate result display orders.');

    $contiguityViolations = $connection->query(
        'SELECT result_id
         FROM result_scores
         GROUP BY result_id
         HAVING SUM(display_order IS NULL) > 0
             OR MIN(display_order) <> 1
             OR MAX(display_order) <> COUNT(*)
             OR COUNT(DISTINCT display_order) <> COUNT(*)'
    )->fetchAll();
    resultDisplayOrderAssert(
        $contiguityViolations === [],
        'Migration 010 did not produce contiguous positive display orders.',
    );
}

function resultDisplayOrderAssertMigrationRejected(PDO $connection, string $state, int $scoreId): void
{
    if ($state === 'null') {
        $connection->prepare('UPDATE result_scores SET display_order = NULL WHERE id = :id')->execute(['id' => $scoreId]);
    }
    if ($state === 'zero') {
        $connection->prepare('UPDATE result_scores SET display_order = 0 WHERE id = :id')->execute(['id' => $scoreId]);
    }
    if ($state === 'duplicate') {
        $connection->prepare('UPDATE result_scores SET display_order = 1 WHERE id = :id')->execute(['id' => $scoreId]);
    }

    resultDisplayOrderExpectDatabaseRejection(
        static fn() => resultDisplayOrderApplyMigrations(
            $connection,
            ['011_enforce_result_display_order_contract.sql'],
        ),
        'Migration 011 accepted legacy ' . $state . ' display-order data.',
    );
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

resultDisplayOrderAssert(
    $host === '127.0.0.1'
    && $port === '3306'
    && $databaseName === RESULT_DISPLAY_ORDER_CONTRACT_TEST_DATABASE,
    'Unsafe test database configuration.',
);
resultDisplayOrderAssert(
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

    foreach (['null', 'zero', 'duplicate'] as $invalidState) {
        $connection = resultDisplayOrderCreateDatabase($admin, $username, $password);
        resultDisplayOrderApplyMigrations($connection, RESULT_DISPLAY_ORDER_PRE_DISPLAY_ORDER_MIGRATIONS);
        $invalidFixture = resultDisplayOrderCreateLegacyFixture($connection);
        resultDisplayOrderApplyMigrations($connection, ['010_add_historical_result_presentation_snapshot.sql']);
        $invalidScoreId = $invalidState === 'duplicate'
            ? $invalidFixture['score_ids']['first'][2]
            : $invalidFixture['score_ids']['first'][0];
        resultDisplayOrderAssertMigrationRejected($connection, $invalidState, $invalidScoreId);
    }

    $connection = resultDisplayOrderCreateDatabase($admin, $username, $password);
    resultDisplayOrderApplyMigrations($connection, RESULT_DISPLAY_ORDER_PRE_DISPLAY_ORDER_MIGRATIONS);
    $fixture = resultDisplayOrderCreateLegacyFixture($connection);
    resultDisplayOrderApplyMigrations($connection, ['010_add_historical_result_presentation_snapshot.sql']);

    $legacyRows = resultDisplayOrderRows($connection);
    resultDisplayOrderAssert(
        array_map(static fn(array $row): int => (int) $row['display_order'], array_slice($legacyRows, 0, 3)) === [1, 2, 3]
        && array_map(static fn(array $row): int => (int) $row['display_order'], array_slice($legacyRows, 3, 1)) === [1],
        'Migration 010 legacy display-order backfill is not available for the contract migration.',
    );
    resultDisplayOrderAssertLegacyPreflight($connection);

    resultDisplayOrderApplyMigrations($connection, ['011_enforce_result_display_order_contract.sql']);

    resultDisplayOrderAssert(
        resultDisplayOrderRows($connection) === $legacyRows,
        'Migration 011 changed existing legacy result-score values.',
    );

    $columns = resultDisplayOrderColumns($connection);
    resultDisplayOrderAssert(
        isset($columns['display_order'])
        && strtolower((string) $columns['display_order']['column_type']) === 'int(10) unsigned'
        && (string) $columns['display_order']['is_nullable'] === 'NO',
        'Migration 011 did not require an unsigned non-null display order.',
    );
    $indexes = resultDisplayOrderIndexes($connection);
    resultDisplayOrderAssert(
        isset($indexes['result_scores_result_display_order_unique'])
        && $indexes['result_scores_result_display_order_unique'] === [
            'non_unique' => 0,
            'columns' => ['result_id', 'display_order'],
        ],
        'Migration 011 did not create the result-local display-order unique key.',
    );
    $checkStatement = $connection->prepare(
        'SELECT constraint_name
         FROM information_schema.table_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = :table_name
           AND constraint_type = :constraint_type'
    );
    $checkStatement->execute([
        'table_name' => 'result_scores',
        'constraint_type' => 'CHECK',
    ]);
    $checkNames = array_map(static fn(array $row): string => (string) $row['constraint_name'], $checkStatement->fetchAll());
    resultDisplayOrderAssert(
        in_array('result_scores_display_order_positive', $checkNames, true),
        'Migration 011 did not create the positive display-order check constraint.',
    );

    resultDisplayOrderExpectDatabaseRejection(
        static fn() => $connection->prepare(
            'INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, display_order, created_at)
             VALUES (:result_id, :program_id, 1, 1, NULL, UTC_TIMESTAMP())'
        )->execute([
            'result_id' => $fixture['result_ids']['first'],
            'program_id' => $fixture['program_ids']['DELTA'],
        ]),
        'Result scores accepted a null display order after migration 011.',
    );
    resultDisplayOrderExpectDatabaseRejection(
        static fn() => $connection->prepare(
            'INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, display_order, created_at)
             VALUES (:result_id, :program_id, 1, 1, 0, UTC_TIMESTAMP())'
        )->execute([
            'result_id' => $fixture['result_ids']['first'],
            'program_id' => $fixture['program_ids']['DELTA'],
        ]),
        'Result scores accepted display order zero after migration 011.',
    );
    resultDisplayOrderExpectDatabaseRejection(
        static fn() => $connection->prepare(
            'INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, display_order, created_at)
             VALUES (:result_id, :program_id, 1, 1, 1, UTC_TIMESTAMP())'
        )->execute([
            'result_id' => $fixture['result_ids']['first'],
            'program_id' => $fixture['program_ids']['DELTA'],
        ]),
        'Result scores accepted duplicate display order within one result after migration 011.',
    );

    $insertValidScore = $connection->prepare(
        'INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, display_order, created_at)
         VALUES (:result_id, :program_id, 1, 25, :display_order, UTC_TIMESTAMP())'
    );
    $thirdResultScoreStatement = $connection->prepare(
        'SELECT COUNT(*) FROM result_scores WHERE result_id = :result_id'
    );
    $thirdResultScoreStatement->execute(['result_id' => $fixture['result_ids']['third']]);
    $thirdResultScoreCount = (int) $thirdResultScoreStatement->fetchColumn();
    resultDisplayOrderAssert(
        $thirdResultScoreCount === 0,
        'Fresh third result already has result-score rows before cross-result reuse.',
    );
    $insertValidScore->execute([
        'result_id' => $fixture['result_ids']['first'],
        'program_id' => $fixture['program_ids']['DELTA'],
        'display_order' => 4,
    ]);
    $insertValidScore->execute([
        'result_id' => $fixture['result_ids']['third'],
        'program_id' => $fixture['program_ids']['BETA'],
        'display_order' => 1,
    ]);
    $orderOneStatement = $connection->prepare(
        'SELECT result_id, program_id, display_order
         FROM result_scores
         WHERE result_id IN (:first_result_id, :third_result_id)
           AND display_order = 1
         ORDER BY result_id ASC'
    );
    $orderOneStatement->execute([
        'first_result_id' => $fixture['result_ids']['first'],
        'third_result_id' => $fixture['result_ids']['third'],
    ]);
    $orderOneRows = $orderOneStatement->fetchAll();
    resultDisplayOrderAssert(
        count($orderOneRows) === 2
        && (int) $orderOneRows[0]['result_id'] === $fixture['result_ids']['first']
        && (int) $orderOneRows[1]['result_id'] === $fixture['result_ids']['third']
        && (int) $orderOneRows[0]['display_order'] === 1
        && (int) $orderOneRows[1]['display_order'] === 1
        && $fixture['result_ids']['first'] !== $fixture['result_ids']['third'],
        'Display order one was not independently accepted for different results.',
    );

    echo "Result display-order contract migration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . RESULT_DISPLAY_ORDER_CONTRACT_TEST_DATABASE);
    }
}
