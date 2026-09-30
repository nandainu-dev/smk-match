<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

const TEST_DATABASE = 'smk_match_g11_r3_test';

function resultMigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectResultMigrationConstraintViolation(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @return list<string> */
function resultMigrationStatements(string $path): array
{
    $contents = file_get_contents($path);
    resultMigrationAssert($contents !== false, 'Migration could not be read: ' . basename($path));

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

/** @return array<string, array<string, mixed>> */
function resultMigrationColumns(PDO $connection, string $table): array
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
function resultMigrationIndexes(PDO $connection, string $table): array
{
    $statement = $connection->prepare(
        'SELECT index_name, non_unique, seq_in_index, column_name
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
         ORDER BY index_name ASC, seq_in_index ASC'
    );
    $statement->execute(['table_name' => $table]);
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

/** @return array<string, array<string, mixed>> */
function resultMigrationForeignKeys(PDO $connection, string $table): array
{
    $statement = $connection->prepare(
        'SELECT kcu.constraint_name, kcu.column_name, kcu.referenced_table_name, kcu.referenced_column_name, rc.delete_rule
         FROM information_schema.key_column_usage AS kcu
         INNER JOIN information_schema.referential_constraints AS rc
             ON rc.constraint_schema = kcu.constraint_schema
            AND rc.constraint_name = kcu.constraint_name
            AND rc.table_name = kcu.table_name
         WHERE kcu.table_schema = DATABASE()
           AND kcu.table_name = :table_name
           AND kcu.referenced_table_name IS NOT NULL'
    );
    $statement->execute(['table_name' => $table]);
    $foreignKeys = [];

    foreach ($statement->fetchAll() as $foreignKey) {
        $foreignKeys[(string) $foreignKey['column_name']] = $foreignKey;
    }

    return $foreignKeys;
}

function resultMigrationApproximately(float $actual, float $expected, float $epsilon = 1.0E-10): bool
{
    return abs($actual - $expected) <= $epsilon * max(1.0, abs($actual), abs($expected));
}

function createResultMigrationAttempt(PDO $connection, int $campaignId, int $batchId, int $quizVersionId, int $sequence): int
{
    $statement = $connection->prepare(
        'INSERT INTO attempts (
            campaign_id,
            campaign_batch_id,
            quiz_version_id,
            visitor_uuid,
            attempt_uuid,
            status,
            submitted_at,
            created_at
        ) VALUES (
            :campaign_id,
            :campaign_batch_id,
            :quiz_version_id,
            :visitor_uuid,
            :attempt_uuid,
            :status,
            :submitted_at,
            :created_at
        )'
    );
    $statement->execute([
        'campaign_id' => $campaignId,
        'campaign_batch_id' => $batchId,
        'quiz_version_id' => $quizVersionId,
        'visitor_uuid' => sprintf('%08d-0000-4000-8000-%012d', $sequence, $sequence),
        'attempt_uuid' => sprintf('%08d-0000-4000-9000-%012d', $sequence, $sequence),
        'status' => 'completed',
        'submitted_at' => '2026-02-01 10:00:00',
        'created_at' => '2026-02-01 09:00:00',
    ]);

    return (int) $connection->lastInsertId();
}

function createResultMigrationBatch(PDO $connection, int $campaignId, int $quizVersionId): int
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
            1,
            :quiz_version_id,
            :label,
            :status,
            1,
            UTC_TIMESTAMP(),
            NULL,
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP()
        )'
    );
    $statement->execute([
        'campaign_id' => $campaignId,
        'quiz_version_id' => $quizVersionId,
        'label' => 'Result migration batch',
        'status' => 'active',
    ]);

    return (int) $connection->lastInsertId();
}

/** @return array<string, mixed> */
function resultMigrationRow(PDO $connection, int $resultId): array
{
    $statement = $connection->prepare(
        'SELECT id, attempt_id, total_raw_score, dominant_program_id, is_tie, created_at
         FROM results
         WHERE id = :id'
    );
    $statement->execute(['id' => $resultId]);
    $row = $statement->fetch();
    resultMigrationAssert(is_array($row), 'Expected result was not found.');

    return $row;
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

resultMigrationAssert(
    $host === '127.0.0.1'
        && $port === '3306'
        && $databaseName === TEST_DATABASE,
    'Unsafe test database configuration.',
);
resultMigrationAssert(
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
        '008_add_attempt_batch_lifecycle.sql',
    ];
    foreach ($baseMigrations as $migrationName) {
        foreach (resultMigrationStatements(SMK_MATCH_ROOT . '/database/migrations/' . $migrationName) as $statement) {
            $connection->exec($statement);
        }
    }

    $resultsBefore = resultMigrationColumns($connection, 'results');
    $scoresBefore = resultMigrationColumns($connection, 'result_scores');
    resultMigrationAssert(!isset($resultsBefore['total_raw_score']), 'total_raw_score must be absent before migration 009.');
    resultMigrationAssert(!isset($resultsBefore['is_tie']), 'is_tie must be absent before migration 009.');
    resultMigrationAssert($scoresBefore['raw_score']['column_type'] === 'decimal(12,2)', 'raw_score must be DECIMAL before migration 009.');
    resultMigrationAssert($scoresBefore['normalized_percentage']['column_type'] === 'decimal(7,4)', 'normalized_percentage must be DECIMAL before migration 009.');
    $participantsBefore = resultMigrationColumns($connection, 'participants');
    $attemptsBefore = resultMigrationColumns($connection, 'attempts');
    $responsesBefore = resultMigrationColumns($connection, 'responses');

    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('aaaaaaaa-1111-4111-8111-111111111111', 'Result Migration School', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolId = (int) $connection->lastInsertId();
    $programInsert = $connection->prepare(
        'INSERT INTO programs (school_id, name, short_name, created_at, updated_at)
         VALUES (:school_id, :name, :short_name, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $programIds = [];
    foreach (['ALPHA', 'BETA', 'GAMMA'] as $code) {
        $programInsert->execute([
            'school_id' => $schoolId,
            'name' => 'Result Migration ' . $code,
            'short_name' => $code,
        ]);
        $programIds[$code] = (int) $connection->lastInsertId();
    }
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolId}, 'Result Migration Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES ({$quizId}, 1, 'published', 'Result Migration Version', UTC_TIMESTAMP())"
    );
    $quizVersionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at)
         VALUES ({$schoolId}, {$quizId}, {$quizVersionId}, 'Result Migration Campaign', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $campaignId = (int) $connection->lastInsertId();
    $batchId = createResultMigrationBatch($connection, $campaignId, $quizVersionId);
    $legacyAttemptId = createResultMigrationAttempt($connection, $campaignId, $batchId, $quizVersionId, 1);

    $legacyResultInsert = $connection->prepare(
        'INSERT INTO results (attempt_id, dominant_program_id, created_at)
         VALUES (:attempt_id, :dominant_program_id, :created_at)'
    );
    $legacyResultInsert->execute([
        'attempt_id' => $legacyAttemptId,
        'dominant_program_id' => $programIds['ALPHA'],
        'created_at' => '2026-02-01 10:01:02',
    ]);
    $legacyResultId = (int) $connection->lastInsertId();
    $legacyScoreInsert = $connection->prepare(
        'INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, created_at)
         VALUES (:result_id, :program_id, :raw_score, :normalized_percentage, :created_at)'
    );
    $legacyScoreInsert->execute([
        'result_id' => $legacyResultId,
        'program_id' => $programIds['ALPHA'],
        'raw_score' => 13.33,
        'normalized_percentage' => 84.21,
        'created_at' => '2026-02-01 10:01:02',
    ]);
    $legacyScoreInsert->execute([
        'result_id' => $legacyResultId,
        'program_id' => $programIds['BETA'],
        'raw_score' => 2.5,
        'normalized_percentage' => 15.79,
        'created_at' => '2026-02-01 10:01:02',
    ]);
    $legacyResultBefore = [
        'attempt_id' => $legacyAttemptId,
        'dominant_program_id' => $programIds['ALPHA'],
        'created_at' => '2026-02-01 10:01:02',
        'scores' => [
            'ALPHA' => ['raw' => 13.33, 'percentage' => 84.21],
            'BETA' => ['raw' => 2.5, 'percentage' => 15.79],
        ],
    ];

    foreach (resultMigrationStatements(SMK_MATCH_ROOT . '/database/migrations/009_add_result_tie_precision.sql') as $statement) {
        $connection->exec($statement);
    }

    $results = resultMigrationColumns($connection, 'results');
    $scores = resultMigrationColumns($connection, 'result_scores');
    resultMigrationAssert($scores['raw_score']['column_type'] === 'double', 'raw_score must be DOUBLE after migration 009.');
    resultMigrationAssert($scores['normalized_percentage']['column_type'] === 'double', 'normalized_percentage must be DOUBLE after migration 009.');
    resultMigrationAssert(isset($results['total_raw_score']) && $results['total_raw_score']['column_type'] === 'double', 'total_raw_score must be DOUBLE.');
    resultMigrationAssert($results['total_raw_score']['is_nullable'] === 'NO' && $results['total_raw_score']['column_default'] === null, 'total_raw_score must be NOT NULL without a default.');
    resultMigrationAssert(isset($results['is_tie']) && $results['is_tie']['column_type'] === 'tinyint(1)', 'is_tie must be TINYINT(1).');
    resultMigrationAssert($results['is_tie']['is_nullable'] === 'NO' && $results['is_tie']['column_default'] === null, 'is_tie must be NOT NULL without a default.');
    resultMigrationAssert($results['dominant_program_id']['is_nullable'] === 'YES', 'dominant_program_id must remain nullable.');
    resultMigrationAssert(
        resultMigrationColumns($connection, 'participants') === $participantsBefore
            && resultMigrationColumns($connection, 'attempts') === $attemptsBefore
            && resultMigrationColumns($connection, 'responses') === $responsesBefore,
        'Migration 009 changed participant, attempt, or response schema.',
    );

    $tiedColumns = resultMigrationColumns($connection, 'result_tied_programs');
    resultMigrationAssert(array_keys($tiedColumns) === ['result_id', 'program_id'], 'Unexpected tied-program schema.');
    $tiedIndexes = resultMigrationIndexes($connection, 'result_tied_programs');
    resultMigrationAssert(
        $tiedIndexes['PRIMARY'] === ['non_unique' => 0, 'columns' => ['result_id', 'program_id']],
        'Tied-program composite primary key is incorrect.',
    );
    $tiedForeignKeys = resultMigrationForeignKeys($connection, 'result_tied_programs');
    foreach ([
        'result_id' => ['constraint' => 'result_tied_programs_result_fk', 'table' => 'results'],
        'program_id' => ['constraint' => 'result_tied_programs_program_fk', 'table' => 'programs'],
    ] as $column => $expected) {
        resultMigrationAssert(
            isset($tiedForeignKeys[$column])
                && $tiedForeignKeys[$column]['constraint_name'] === $expected['constraint']
                && $tiedForeignKeys[$column]['referenced_table_name'] === $expected['table']
                && $tiedForeignKeys[$column]['referenced_column_name'] === 'id'
                && $tiedForeignKeys[$column]['delete_rule'] === 'RESTRICT',
            'Tied-program foreign key is incorrect: ' . $column,
        );
    }
    $scoreIndexes = resultMigrationIndexes($connection, 'result_scores');
    resultMigrationAssert(
        $scoreIndexes['result_scores_result_program_unique'] === ['non_unique' => 0, 'columns' => ['result_id', 'program_id']],
        'Result score uniqueness was not preserved.',
    );

    $legacyResult = resultMigrationRow($connection, $legacyResultId);
    resultMigrationAssert(
        (int) $legacyResult['attempt_id'] === $legacyResultBefore['attempt_id']
            && (int) $legacyResult['dominant_program_id'] === $legacyResultBefore['dominant_program_id']
            && $legacyResult['created_at'] === $legacyResultBefore['created_at']
            && (int) $legacyResult['is_tie'] === 0
            && resultMigrationApproximately((float) $legacyResult['total_raw_score'], 15.83),
        'Legacy result was not preserved with the deterministic non-tie policy.',
    );
    $legacyScores = $connection->query(
        "SELECT p.short_name, raw_score, normalized_percentage
         FROM result_scores AS rs
         INNER JOIN programs AS p ON p.id = rs.program_id
         WHERE rs.result_id = {$legacyResultId}
         ORDER BY p.short_name ASC"
    )->fetchAll();
    resultMigrationAssert(count($legacyScores) === 2, 'Legacy score rows were lost.');
    foreach ($legacyScores as $score) {
        $code = (string) $score['short_name'];
        resultMigrationAssert(
            resultMigrationApproximately((float) $score['raw_score'], $legacyResultBefore['scores'][$code]['raw'])
                && resultMigrationApproximately((float) $score['normalized_percentage'], $legacyResultBefore['scores'][$code]['percentage']),
            'Legacy score changed: ' . $code,
        );
    }
    resultMigrationAssert(
        (int) $connection->query("SELECT COUNT(*) FROM result_tied_programs WHERE result_id = {$legacyResultId}")->fetchColumn() === 0,
        'Legacy tied-program rows were fabricated.',
    );

    $nonTieAttemptId = createResultMigrationAttempt($connection, $campaignId, $batchId, $quizVersionId, 2);
    $resultInsert = $connection->prepare(
        'INSERT INTO results (attempt_id, total_raw_score, dominant_program_id, is_tie, created_at)
         VALUES (:attempt_id, :total_raw_score, :dominant_program_id, :is_tie, :created_at)'
    );
    $resultInsert->execute([
        'attempt_id' => $nonTieAttemptId,
        'total_raw_score' => 16.000000000000,
        'dominant_program_id' => $programIds['ALPHA'],
        'is_tie' => 0,
        'created_at' => '2026-02-01 11:00:00',
    ]);
    $nonTieResultId = (int) $connection->lastInsertId();
    $scoreInsert = $connection->prepare(
        'INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, created_at)
         VALUES (:result_id, :program_id, :raw_score, :normalized_percentage, :created_at)'
    );
    foreach ([
        'ALPHA' => ['raw' => 13.333333333333, 'percentage' => 83.3333333333],
        'BETA' => ['raw' => 2.666666666667, 'percentage' => 16.6666666667],
        'GAMMA' => ['raw' => 0.0, 'percentage' => 0.0],
    ] as $code => $score) {
        $scoreInsert->execute([
            'result_id' => $nonTieResultId,
            'program_id' => $programIds[$code],
            'raw_score' => $score['raw'],
            'normalized_percentage' => $score['percentage'],
            'created_at' => '2026-02-01 11:00:00',
        ]);
    }
    $nonTieResult = resultMigrationRow($connection, $nonTieResultId);
    resultMigrationAssert(
        (int) $nonTieResult['is_tie'] === 0
            && (int) $nonTieResult['dominant_program_id'] === $programIds['ALPHA']
            && resultMigrationApproximately((float) $nonTieResult['total_raw_score'], 16.0)
            && (int) $connection->query("SELECT COUNT(*) FROM result_tied_programs WHERE result_id = {$nonTieResultId}")->fetchColumn() === 0,
        'Modern non-tie result is invalid.',
    );
    $precisionScores = $connection->query(
        "SELECT p.short_name, raw_score, normalized_percentage
         FROM result_scores AS rs
         INNER JOIN programs AS p ON p.id = rs.program_id
         WHERE rs.result_id = {$nonTieResultId}
         ORDER BY p.short_name ASC"
    )->fetchAll();
    foreach ($precisionScores as $score) {
        $code = (string) $score['short_name'];
        $expected = [
            'ALPHA' => ['raw' => 13.333333333333, 'percentage' => 83.3333333333],
            'BETA' => ['raw' => 2.666666666667, 'percentage' => 16.6666666667],
            'GAMMA' => ['raw' => 0.0, 'percentage' => 0.0],
        ][$code];
        resultMigrationAssert(
            resultMigrationApproximately((float) $score['raw_score'], $expected['raw'])
                && resultMigrationApproximately((float) $score['normalized_percentage'], $expected['percentage']),
            'DOUBLE precision round-trip failed: ' . $code,
        );
    }

    $tieAttemptId = createResultMigrationAttempt($connection, $campaignId, $batchId, $quizVersionId, 3);
    $resultInsert->execute([
        'attempt_id' => $tieAttemptId,
        'total_raw_score' => 20.0,
        'dominant_program_id' => null,
        'is_tie' => 1,
        'created_at' => '2026-02-01 12:00:00',
    ]);
    $tieResultId = (int) $connection->lastInsertId();
    foreach ([
        'ALPHA' => ['raw' => 10.0, 'percentage' => 50.0],
        'BETA' => ['raw' => 10.0, 'percentage' => 50.0],
        'GAMMA' => ['raw' => 0.0, 'percentage' => 0.0],
    ] as $code => $score) {
        $scoreInsert->execute([
            'result_id' => $tieResultId,
            'program_id' => $programIds[$code],
            'raw_score' => $score['raw'],
            'normalized_percentage' => $score['percentage'],
            'created_at' => '2026-02-01 12:00:00',
        ]);
    }
    $tiedProgramInsert = $connection->prepare(
        'INSERT INTO result_tied_programs (result_id, program_id)
         VALUES (:result_id, :program_id)'
    );
    foreach (['ALPHA', 'BETA'] as $code) {
        $tiedProgramInsert->execute([
            'result_id' => $tieResultId,
            'program_id' => $programIds[$code],
        ]);
    }
    $tieResult = resultMigrationRow($connection, $tieResultId);
    $tieMembers = $connection->query(
        "SELECT p.short_name
         FROM result_tied_programs AS rtp
         INNER JOIN programs AS p ON p.id = rtp.program_id
         WHERE rtp.result_id = {$tieResultId}
         ORDER BY p.short_name ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
    resultMigrationAssert(
        (int) $tieResult['is_tie'] === 1
            && $tieResult['dominant_program_id'] === null
            && $tieMembers === ['ALPHA', 'BETA']
            && (int) $connection->query("SELECT COUNT(*) FROM result_scores WHERE result_id = {$tieResultId}")->fetchColumn() === 3,
        'Modern tie result is invalid.',
    );

    expectResultMigrationConstraintViolation(
        static fn () => $tiedProgramInsert->execute([
            'result_id' => $tieResultId,
            'program_id' => $programIds['ALPHA'],
        ]),
        'Duplicate tied-program membership was accepted.',
    );
    expectResultMigrationConstraintViolation(
        static fn () => $tiedProgramInsert->execute([
            'result_id' => 999999,
            'program_id' => $programIds['ALPHA'],
        ]),
        'Invalid tied-program result foreign key was accepted.',
    );
    expectResultMigrationConstraintViolation(
        static fn () => $tiedProgramInsert->execute([
            'result_id' => $tieResultId,
            'program_id' => 999999,
        ]),
        'Invalid tied-program program foreign key was accepted.',
    );
    expectResultMigrationConstraintViolation(
        static fn () => $scoreInsert->execute([
            'result_id' => $nonTieResultId,
            'program_id' => $programIds['ALPHA'],
            'raw_score' => 1.0,
            'normalized_percentage' => 1.0,
            'created_at' => '2026-02-01 13:00:00',
        ]),
        'Duplicate result score was accepted.',
    );
    $connection->exec("DELETE FROM result_scores WHERE result_id = {$tieResultId}");
    expectResultMigrationConstraintViolation(
        static fn () => $connection->exec("DELETE FROM results WHERE id = {$tieResultId}"),
        'Referenced tie result deletion was accepted.',
    );
    $connection->exec("DELETE FROM result_scores WHERE program_id = {$programIds['BETA']}");
    expectResultMigrationConstraintViolation(
        static fn () => $connection->exec("DELETE FROM programs WHERE id = {$programIds['BETA']}"),
        'Referenced tied program deletion was accepted.',
    );

    foreach (['results', 'result_scores', 'result_tied_programs'] as $table) {
        $statement = $connection->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND LOWER(column_name) IN (\'dkv\', \'mplb\', \'pm\', \'dkv_score\', \'mplb_score\', \'pm_score\')'
        );
        $statement->execute(['table_name' => $table]);
        resultMigrationAssert((int) $statement->fetchColumn() === 0, 'Program-specific column found in ' . $table);
    }

    echo "Result tie and precision migration integration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    }
}
