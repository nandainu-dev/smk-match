<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

const TEST_DATABASE = 'smk_match_g11_r12a1_test';

function historicalMigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectHistoricalMigrationConstraintViolation(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @return list<string> */
function historicalMigrationStatements(string $path): array
{
    $contents = file_get_contents($path);
    historicalMigrationAssert($contents !== false, 'Migration could not be read: ' . basename($path));

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

function applyHistoricalMigrations(PDO $connection, array $migrationNames): void
{
    foreach ($migrationNames as $migrationName) {
        foreach (historicalMigrationStatements(SMK_MATCH_ROOT . '/database/migrations/' . $migrationName) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @return array<string, array<string, mixed>> */
function historicalMigrationColumns(PDO $connection, string $table): array
{
    $statement = $connection->prepare(
        'SELECT column_name, column_type, is_nullable
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
function historicalMigrationIndexes(PDO $connection, string $table): array
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
function historicalMigrationForeignKeys(PDO $connection, string $table): array
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

function recreateHistoricalMigrationDatabase(PDO $admin, string $user, string $password): PDO
{
    $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . TEST_DATABASE . ' CHARACTER SET utf8mb4');

    return new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=' . TEST_DATABASE . ';charset=utf8mb4',
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
}

/** @return array<string, int> */
function createHistoricalMigrationFixture(PDO $connection): array
{
    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('12121212-1212-4121-8121-121212121212', 'Historical Migration School', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolId = (int) $connection->lastInsertId();
    $programInsert = $connection->prepare(
        'INSERT INTO programs (
            school_id,
            name,
            short_name,
            personality_title,
            description,
            color_json,
            mascot_path,
            skills_json,
            result_copy,
            created_at,
            updated_at
        ) VALUES (
            :school_id,
            :name,
            :short_name,
            :personality_title,
            :description,
            :color_json,
            :mascot_path,
            :skills_json,
            :result_copy,
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP()
        )'
    );
    $programIds = [];
    foreach ([
        'ZETA' => ['Zeta Program', 'Zeta Planner'],
        'ALPHA' => ['Alpha Program', 'Alpha Maker'],
        'OMEGA' => ['Omega Program', 'Omega Explorer'],
    ] as $code => [$name, $title]) {
        $programInsert->execute([
            'school_id' => $schoolId,
            'name' => $name,
            'short_name' => $code,
            'personality_title' => $title,
            'description' => $code . ' historical description',
            'color_json' => '{"primary":"#123456","accent":"#abcdef"}',
            'mascot_path' => '/assets/mascots/' . strtolower($code) . '.png',
            'skills_json' => '["' . $code . ' skill"]',
            'result_copy' => $code . ' legacy copy',
        ]);
        $programIds[$code] = (int) $connection->lastInsertId();
    }
    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolId}, 'Historical Migration Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
         VALUES ({$quizId}, 1, 'published', 'Historical Migration Version', UTC_TIMESTAMP())"
    );
    $quizVersionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at)
         VALUES ({$quizVersionId}, 'Historical question', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $questionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO question_options (question_id, option_text, sort_order, created_at)
         VALUES ({$questionId}, 'Historical option', 1, UTC_TIMESTAMP())"
    );
    $optionId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO option_weights (question_option_id, program_id, weight, created_at)
         VALUES ({$optionId}, {$programIds['ZETA']}, 3.5, UTC_TIMESTAMP())"
    );
    $membershipInsert = $connection->prepare(
        'INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at)
         VALUES (:quiz_version_id, :program_id, UTC_TIMESTAMP())'
    );
    $membershipIds = [];
    foreach ($programIds as $code => $programId) {
        $membershipInsert->execute([
            'quiz_version_id' => $quizVersionId,
            'program_id' => $programId,
        ]);
        $membershipIds[$code] = (int) $connection->lastInsertId();
    }
    $connection->exec(
        "INSERT INTO program_careers (program_id, title, description, sort_order, created_at)
         VALUES ({$programIds['ZETA']}, 'Historical Career', 'Must not be copied without a direct column.', 1, UTC_TIMESTAMP())"
    );

    $attemptInsert = $connection->prepare(
        'INSERT INTO attempts (quiz_version_id, visitor_uuid, attempt_uuid, status, submitted_at, created_at)
         VALUES (:quiz_version_id, :visitor_uuid, :attempt_uuid, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $resultInsert = $connection->prepare(
        'INSERT INTO results (attempt_id, total_raw_score, dominant_program_id, is_tie, created_at)
         VALUES (:attempt_id, :total_raw_score, :dominant_program_id, :is_tie, UTC_TIMESTAMP())'
    );
    $scoreInsert = $connection->prepare(
        'INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, created_at)
         VALUES (:result_id, :program_id, :raw_score, :normalized_percentage, UTC_TIMESTAMP())'
    );

    $attemptInsert->execute([
        'quiz_version_id' => $quizVersionId,
        'visitor_uuid' => '13131313-1313-4131-8131-131313131313',
        'attempt_uuid' => '14141414-1414-4141-8141-141414141414',
        'status' => 'completed',
    ]);
    $firstAttemptId = (int) $connection->lastInsertId();
    $resultInsert->execute([
        'attempt_id' => $firstAttemptId,
        'total_raw_score' => 18.0,
        'dominant_program_id' => $programIds['ALPHA'],
        'is_tie' => 0,
    ]);
    $firstResultId = (int) $connection->lastInsertId();
    foreach (['OMEGA', 'ZETA', 'ALPHA'] as $code) {
        $scoreInsert->execute([
            'result_id' => $firstResultId,
            'program_id' => $programIds[$code],
            'raw_score' => ['ZETA' => 2.0, 'ALPHA' => 11.0, 'OMEGA' => 5.0][$code],
            'normalized_percentage' => ['ZETA' => 11.11, 'ALPHA' => 61.11, 'OMEGA' => 27.78][$code],
        ]);
    }

    $attemptInsert->execute([
        'quiz_version_id' => $quizVersionId,
        'visitor_uuid' => '15151515-1515-4151-8151-151515151515',
        'attempt_uuid' => '16161616-1616-4161-8161-161616161616',
        'status' => 'completed',
    ]);
    $secondAttemptId = (int) $connection->lastInsertId();
    $resultInsert->execute([
        'attempt_id' => $secondAttemptId,
        'total_raw_score' => 10.0,
        'dominant_program_id' => null,
        'is_tie' => 1,
    ]);
    $secondResultId = (int) $connection->lastInsertId();
    foreach (['ALPHA', 'OMEGA', 'ZETA'] as $code) {
        $scoreInsert->execute([
            'result_id' => $secondResultId,
            'program_id' => $programIds[$code],
            'raw_score' => ['ZETA' => 5.0, 'ALPHA' => 5.0, 'OMEGA' => 0.0][$code],
            'normalized_percentage' => ['ZETA' => 50.0, 'ALPHA' => 50.0, 'OMEGA' => 0.0][$code],
        ]);
    }
    $tiedInsert = $connection->prepare(
        'INSERT INTO result_tied_programs (result_id, program_id) VALUES (:result_id, :program_id)'
    );
    foreach (['ZETA', 'ALPHA'] as $code) {
        $tiedInsert->execute([
            'result_id' => $secondResultId,
            'program_id' => $programIds[$code],
        ]);
    }

    return [
        'quiz_version_id' => $quizVersionId,
        'question_id' => $questionId,
        'option_id' => $optionId,
        'first_result_id' => $firstResultId,
        'second_result_id' => $secondResultId,
        'zeta_program_id' => $programIds['ZETA'],
        'alpha_program_id' => $programIds['ALPHA'],
        'omega_program_id' => $programIds['OMEGA'],
        'zeta_membership_id' => $membershipIds['ZETA'],
    ];
}

/** @return array<string, mixed> */
function legacyResultState(PDO $connection, int $resultId): array
{
    $resultStatement = $connection->prepare(
        'SELECT total_raw_score, dominant_program_id, is_tie
         FROM results
         WHERE id = :id'
    );
    $resultStatement->execute(['id' => $resultId]);
    $result = $resultStatement->fetch();
    historicalMigrationAssert(is_array($result), 'Legacy result is missing.');

    $scoreStatement = $connection->prepare(
        'SELECT program_id, raw_score, normalized_percentage
         FROM result_scores
         WHERE result_id = :result_id
         ORDER BY program_id ASC'
    );
    $scoreStatement->execute(['result_id' => $resultId]);
    $tieStatement = $connection->prepare(
        'SELECT program_id
         FROM result_tied_programs
         WHERE result_id = :result_id
         ORDER BY program_id ASC'
    );
    $tieStatement->execute(['result_id' => $resultId]);

    return [
        'result' => $result,
        'scores' => $scoreStatement->fetchAll(),
        'ties' => $tieStatement->fetchAll(PDO::FETCH_COLUMN),
    ];
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

historicalMigrationAssert(
    $host === '127.0.0.1'
        && $port === '3306'
        && $databaseName === TEST_DATABASE,
    'Unsafe test database configuration.',
);
historicalMigrationAssert(
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
    $allMigrations = [
        '001_create_database_foundation.sql',
        '002_add_school_profile_branding_fields.sql',
        '003_add_quiz_version_name_snapshot.sql',
        '004_create_quiz_version_programs.sql',
        '005_change_option_weight_to_double.sql',
        '006_add_campaign_quiz_version.sql',
        '007_create_campaign_batches.sql',
        '008_add_attempt_batch_lifecycle.sql',
        '009_add_result_tie_precision.sql',
        '010_add_historical_result_presentation_snapshot.sql',
    ];

    $connection = recreateHistoricalMigrationDatabase($admin, $user, $password);
    applyHistoricalMigrations($connection, $allMigrations);
    $presentationColumns = historicalMigrationColumns($connection, 'quiz_version_program_presentations');
    historicalMigrationAssert(
        array_keys($presentationColumns) === [
            'id',
            'quiz_version_program_id',
            'program_code_snapshot',
            'program_name_snapshot',
            'personality_title_snapshot',
            'mascot_path_snapshot',
            'primary_color_snapshot',
            'accent_color_snapshot',
            'tagline_snapshot',
            'description_snapshot',
            'superpower_snapshot',
            'skills_snapshot',
            'careers_snapshot',
            'snapshot_provenance',
            'created_at',
            'updated_at',
        ],
        'Unexpected presentation snapshot schema.',
    );
    historicalMigrationAssert(
        $presentationColumns['quiz_version_program_id']['column_type'] === 'bigint(20) unsigned'
            && $presentationColumns['program_code_snapshot']['column_type'] === 'varchar(100)'
            && $presentationColumns['program_name_snapshot']['column_type'] === 'varchar(255)'
            && $presentationColumns['skills_snapshot']['column_type'] === 'longtext'
            && $presentationColumns['careers_snapshot']['column_type'] === 'longtext',
        'Presentation snapshot column types are incompatible.',
    );
    $presentationIndexes = historicalMigrationIndexes($connection, 'quiz_version_program_presentations');
    historicalMigrationAssert(
        $presentationIndexes['quiz_version_program_presentations_membership_unique'] === [
            'non_unique' => 0,
            'columns' => ['quiz_version_program_id'],
        ],
        'Presentation membership uniqueness is missing.',
    );
    $presentationForeignKeys = historicalMigrationForeignKeys($connection, 'quiz_version_program_presentations');
    historicalMigrationAssert(
        isset($presentationForeignKeys['quiz_version_program_id'])
            && $presentationForeignKeys['quiz_version_program_id']['constraint_name'] === 'quiz_version_program_presentations_membership_fk'
            && $presentationForeignKeys['quiz_version_program_id']['referenced_table_name'] === 'quiz_version_programs'
            && $presentationForeignKeys['quiz_version_program_id']['referenced_column_name'] === 'id'
            && $presentationForeignKeys['quiz_version_program_id']['delete_rule'] === 'RESTRICT',
        'Presentation membership foreign key is incorrect.',
    );
    $freshScoreColumns = historicalMigrationColumns($connection, 'result_scores');
    historicalMigrationAssert(
        isset($freshScoreColumns['display_order'])
            && $freshScoreColumns['display_order']['column_type'] === 'int(10) unsigned'
            && $freshScoreColumns['display_order']['is_nullable'] === 'YES',
        'display_order must be an unsigned nullable integer during the expand phase.',
    );
    foreach (historicalMigrationIndexes($connection, 'result_scores') as $index) {
        historicalMigrationAssert(
            $index['columns'] !== ['result_id', 'display_order'],
            'Migration 010 must not create a display-order uniqueness key.',
        );
    }

    $connection = recreateHistoricalMigrationDatabase($admin, $user, $password);
    applyHistoricalMigrations($connection, array_slice($allMigrations, 0, 9));
    $fixture = createHistoricalMigrationFixture($connection);
    $membershipBefore = $connection->query(
        'SELECT id, quiz_version_id, program_id FROM quiz_version_programs ORDER BY id ASC'
    )->fetchAll();
    $questionBefore = $connection->query(
        "SELECT id, quiz_version_id, prompt, sort_order FROM questions WHERE id = {$fixture['question_id']}"
    )->fetch();
    $optionBefore = $connection->query(
        "SELECT id, question_id, option_text, sort_order FROM question_options WHERE id = {$fixture['option_id']}"
    )->fetch();
    $weightBefore = $connection->query(
        "SELECT question_option_id, program_id, weight FROM option_weights WHERE question_option_id = {$fixture['option_id']}"
    )->fetch();
    $firstResultBefore = legacyResultState($connection, $fixture['first_result_id']);
    $secondResultBefore = legacyResultState($connection, $fixture['second_result_id']);

    applyHistoricalMigrations($connection, ['010_add_historical_result_presentation_snapshot.sql']);

    $presentationRows = $connection->query(
        'SELECT qvp.id AS membership_id, p.short_name, p.name, p.personality_title, p.mascot_path, p.description, p.skills_json,
                qvpp.program_code_snapshot, qvpp.program_name_snapshot, qvpp.personality_title_snapshot,
                qvpp.mascot_path_snapshot, qvpp.primary_color_snapshot, qvpp.accent_color_snapshot,
                qvpp.tagline_snapshot, qvpp.description_snapshot, qvpp.superpower_snapshot,
                qvpp.skills_snapshot, qvpp.careers_snapshot, qvpp.snapshot_provenance
         FROM quiz_version_program_presentations AS qvpp
         INNER JOIN quiz_version_programs AS qvp ON qvp.id = qvpp.quiz_version_program_id
         INNER JOIN programs AS p ON p.id = qvp.program_id
         ORDER BY qvp.id ASC'
    )->fetchAll();
    historicalMigrationAssert(
        count($presentationRows) === count($membershipBefore),
        'Migration 010 must create one presentation snapshot for every legacy membership.',
    );
    foreach ($presentationRows as $presentationRow) {
        historicalMigrationAssert(
            $presentationRow['program_code_snapshot'] === $presentationRow['short_name']
                && $presentationRow['program_name_snapshot'] === $presentationRow['name']
                && $presentationRow['personality_title_snapshot'] === $presentationRow['personality_title']
                && $presentationRow['mascot_path_snapshot'] === $presentationRow['mascot_path']
                && $presentationRow['description_snapshot'] === $presentationRow['description']
                && $presentationRow['skills_snapshot'] === $presentationRow['skills_json']
                && $presentationRow['primary_color_snapshot'] === null
                && $presentationRow['accent_color_snapshot'] === null
                && $presentationRow['tagline_snapshot'] === null
                && $presentationRow['superpower_snapshot'] === null
                && $presentationRow['careers_snapshot'] === null
                && $presentationRow['snapshot_provenance'] === 'legacy_current_catalog',
            'Legacy presentation snapshot does not match the authorized database-only mapping.',
        );
    }

    $firstOrdering = $connection->query(
        "SELECT program_id, display_order
         FROM result_scores
         WHERE result_id = {$fixture['first_result_id']}
         ORDER BY display_order ASC"
    )->fetchAll();
    $firstProgramIds = array_map('intval', array_column($firstOrdering, 'program_id'));
    $firstDisplayOrders = array_map('intval', array_column($firstOrdering, 'display_order'));
    historicalMigrationAssert(
        $firstProgramIds === [
            $fixture['zeta_program_id'],
            $fixture['alpha_program_id'],
            $fixture['omega_program_id'],
        ]
            && $firstDisplayOrders === [1, 2, 3],
        'Legacy result one display order must be one-based program_id ascending.',
    );
    $secondOrdering = $connection->query(
        "SELECT program_id, display_order
         FROM result_scores
         WHERE result_id = {$fixture['second_result_id']}
         ORDER BY display_order ASC"
    )->fetchAll();
    $secondProgramIds = array_map('intval', array_column($secondOrdering, 'program_id'));
    $secondDisplayOrders = array_map('intval', array_column($secondOrdering, 'display_order'));
    historicalMigrationAssert(
        $secondProgramIds === [
            $fixture['zeta_program_id'],
            $fixture['alpha_program_id'],
            $fixture['omega_program_id'],
        ]
            && $secondDisplayOrders === [1, 2, 3],
        'Legacy result two display order must restart at one.',
    );

    $firstResultAfter = legacyResultState($connection, $fixture['first_result_id']);
    $secondResultAfter = legacyResultState($connection, $fixture['second_result_id']);
    historicalMigrationAssert(
        $firstResultAfter === $firstResultBefore && $secondResultAfter === $secondResultBefore,
        'Migration 010 changed legacy result, score, or tie values.',
    );
    historicalMigrationAssert(
        $connection->query('SELECT id, quiz_version_id, program_id FROM quiz_version_programs ORDER BY id ASC')->fetchAll() === $membershipBefore
            && $connection->query("SELECT id, quiz_version_id, prompt, sort_order FROM questions WHERE id = {$fixture['question_id']}")->fetch() === $questionBefore
            && $connection->query("SELECT id, question_id, option_text, sort_order FROM question_options WHERE id = {$fixture['option_id']}")->fetch() === $optionBefore
            && $connection->query("SELECT question_option_id, program_id, weight FROM option_weights WHERE question_option_id = {$fixture['option_id']}")->fetch() === $weightBefore,
        'Migration 010 changed quiz version membership, question, option, or weight data.',
    );

    expectHistoricalMigrationConstraintViolation(
        static fn () => $connection->exec(
            "INSERT INTO quiz_version_program_presentations (
                quiz_version_program_id,
                program_code_snapshot,
                program_name_snapshot,
                snapshot_provenance,
                created_at,
                updated_at
            ) VALUES (
                {$fixture['zeta_membership_id']},
                'DUPLICATE',
                'Duplicate presentation',
                'legacy_current_catalog',
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )"
        ),
        'Duplicate presentation membership was accepted.',
    );
    $connection->exec(
        "INSERT INTO programs (school_id, name, short_name, created_at, updated_at)
         SELECT school_id, 'Null Check Program', 'NULLCHECK', UTC_TIMESTAMP(), UTC_TIMESTAMP()
         FROM programs
         WHERE id = {$fixture['zeta_program_id']}"
    );
    $nullCheckProgramId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at)
         VALUES ({$fixture['quiz_version_id']}, {$nullCheckProgramId}, UTC_TIMESTAMP())"
    );
    $nullCheckMembershipId = (int) $connection->lastInsertId();
    foreach (['program_code_snapshot', 'program_name_snapshot', 'snapshot_provenance'] as $requiredColumn) {
        expectHistoricalMigrationConstraintViolation(
            static fn () => $connection->exec(
                "INSERT INTO quiz_version_program_presentations (
                    quiz_version_program_id,
                    program_code_snapshot,
                    program_name_snapshot,
                    snapshot_provenance,
                    created_at,
                    updated_at
                ) VALUES (
                    {$nullCheckMembershipId},
                    " . ($requiredColumn === 'program_code_snapshot' ? 'NULL' : "'VALID'") . ",
                    " . ($requiredColumn === 'program_name_snapshot' ? 'NULL' : "'Valid name'") . ",
                    " . ($requiredColumn === 'snapshot_provenance' ? 'NULL' : "'legacy_current_catalog'") . ",
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP()
                )"
            ),
            'Required presentation column accepted NULL: ' . $requiredColumn,
        );
    }

    $connection->exec(
        "INSERT INTO attempts (quiz_version_id, visitor_uuid, attempt_uuid, status, submitted_at, created_at)
         VALUES ({$fixture['quiz_version_id']}, '17171717-1717-4171-8171-171717171717', '18181818-1818-4181-8181-181818181818', 'completed', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $transitionalAttemptId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO results (attempt_id, total_raw_score, dominant_program_id, is_tie, created_at)
         VALUES ({$transitionalAttemptId}, 1.0, {$fixture['zeta_program_id']}, 0, UTC_TIMESTAMP())"
    );
    $transitionalResultId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, created_at)
         VALUES ({$transitionalResultId}, {$fixture['zeta_program_id']}, 1.0, 100.0, UTC_TIMESTAMP())"
    );
    historicalMigrationAssert(
        $connection->query(
            "SELECT display_order FROM result_scores WHERE result_id = {$transitionalResultId}"
        )->fetchColumn() === null,
        'The locked pre-R12A2 writer shape must retain a NULL transitional display order.',
    );

    echo "Historical result snapshot migration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    }
}
