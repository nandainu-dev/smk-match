<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\QuizDefinition;
use App\Core\QuizVersion;
use App\Core\QuizVersionRepository;
const TEST_DATABASE = 'smk_match_g7_repository_test';

function repositoryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function repositoryInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function repositoryRuntime(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

function binary64(float $value): string
{
    return bin2hex(pack('E', $value));
}

function snapshotGraphCounts(PDO $connection, int $quizId): array
{
    $queries = [
        'versions' => 'SELECT COUNT(*) FROM quiz_versions WHERE quiz_id = :quiz_id',
        'memberships' => 'SELECT COUNT(*) FROM quiz_version_programs AS qvp INNER JOIN quiz_versions AS qv ON qv.id = qvp.quiz_version_id WHERE qv.quiz_id = :quiz_id',
        'questions' => 'SELECT COUNT(*) FROM questions AS q INNER JOIN quiz_versions AS qv ON qv.id = q.quiz_version_id WHERE qv.quiz_id = :quiz_id',
        'options' => 'SELECT COUNT(*) FROM question_options AS qo INNER JOIN questions AS q ON q.id = qo.question_id INNER JOIN quiz_versions AS qv ON qv.id = q.quiz_version_id WHERE qv.quiz_id = :quiz_id',
        'weights' => 'SELECT COUNT(*) FROM option_weights AS ow INNER JOIN question_options AS qo ON qo.id = ow.question_option_id INNER JOIN questions AS q ON q.id = qo.question_id INNER JOIN quiz_versions AS qv ON qv.id = q.quiz_version_id WHERE qv.quiz_id = :quiz_id',
    ];
    $counts = [];

    foreach ($queries as $name => $sql) {
        $statement = $connection->prepare($sql);
        $statement->execute(['quiz_id' => $quizId]);
        $counts[$name] = (int) $statement->fetchColumn();
    }

    return $counts;
}

function assertSnapshotGraphCounts(array $actual, array $expected, string $context): void
{
    foreach ($expected as $name => $count) {
        repositoryAssert(
            array_key_exists($name, $actual) && $actual[$name] === $count,
            $context . ': unexpected ' . $name . ' count.',
        );
    }
}

function testDefinition(int $sourceVersion = 77, array $programs = ['ALPHA' => true, 'BETA' => true, 'GAMMA' => true, 'DELTA' => true]): QuizDefinition
{
    return new QuizDefinition('Historical precision snapshot', $sourceVersion, $programs, [
        ['id' => 'q1', 'text' => 'Question one', 'order' => 1, 'options' => [
            ['id' => 'q1a', 'text' => 'Option one', 'order' => 1, 'weights' => [
                ['program' => 'ALPHA', 'weight' => 0.333],
                ['program' => 'BETA', 'weight' => 1.257],
            ]],
            ['id' => 'q1b', 'text' => 'Option two', 'order' => 2, 'weights' => [
                ['program' => 'GAMMA', 'weight' => 0.000001],
            ]],
        ]],
        ['id' => 'q2', 'text' => 'Question two', 'order' => 2, 'options' => [
            ['id' => 'q2a', 'text' => 'Option one', 'order' => 1, 'weights' => [
                ['program' => 'ALPHA', 'weight' => -1.25],
            ]],
            ['id' => 'q2b', 'text' => 'Option two', 'order' => 2, 'weights' => []],
        ]],
    ]);
}

function foreignSchoolDefinition(): QuizDefinition
{
    return new QuizDefinition('Foreign school snapshot', 77, ['OMEGA' => true], [
        ['id' => 'foreign-q', 'text' => 'Foreign question', 'order' => 1, 'options' => [
            ['id' => 'foreign-a', 'text' => 'Foreign option', 'order' => 1, 'weights' => [
                ['program' => 'OMEGA', 'weight' => 1],
            ]],
            ['id' => 'foreign-b', 'text' => 'Other option', 'order' => 2, 'weights' => []],
        ]],
    ]);
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
repositoryAssert($host === '127.0.0.1' && $port === '3306' && $databaseName === TEST_DATABASE, 'Unsafe test database configuration.');
repositoryAssert(is_string($user) && $user !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $pdo = $database->connection();
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migration) {
        $sql = file_get_contents($migration);
        repositoryAssert($sql !== false, 'Migration could not be read.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
        }
    }
    $fixturePdo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=' . TEST_DATABASE . ';charset=utf8mb4',
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );

    repositoryAssert((string) $fixturePdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" . TEST_DATABASE . "' AND TABLE_NAME = 'option_weights' AND COLUMN_NAME = 'weight'")->fetchColumn() === 'double', 'Migration 005 was not applied.');

    $insertSchool = $fixturePdo->prepare('INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES (:uuid, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $insertSchool->execute(['uuid' => '11111111-1111-1111-1111-111111111111', 'name' => 'School A']);
    $schoolA = (int) $fixturePdo->lastInsertId();
    $insertSchool->execute(['uuid' => '22222222-2222-2222-2222-222222222222', 'name' => 'School B']);
    $schoolB = (int) $fixturePdo->lastInsertId();
    $insertProgram = $fixturePdo->prepare('INSERT INTO programs (school_id, name, short_name, is_active, created_at, updated_at) VALUES (:school_id, :name, :code, :active, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $programIds = [];
    foreach ([[$schoolA, 'ALPHA', 1], [$schoolA, 'BETA', 1], [$schoolA, 'GAMMA', 1], [$schoolA, 'DELTA', 0], [$schoolA, 'EPSILON', 1], [$schoolB, 'ALPHA', 1], [$schoolB, 'OMEGA', 1]] as [$schoolId, $code, $active]) {
        $insertProgram->execute(['school_id' => $schoolId, 'name' => $code, 'code' => $code, 'active' => $active]);
        $programIds[$schoolId . ':' . $code] = (int) $fixturePdo->lastInsertId();
    }
    $insertQuiz = $fixturePdo->prepare('INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES (:school_id, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $insertQuiz->execute(['school_id' => $schoolA, 'name' => 'Quiz A']);
    $quizA = (int) $fixturePdo->lastInsertId();
    $repository = new QuizVersionRepository($database);
    $approvedSnapshot = testDefinition();
    $created = $repository->createDraftSnapshot($quizA, $approvedSnapshot);
    repositoryAssert($created->versionNumber === 1 && $created->versionNumber !== 77 && $created->status === QuizVersion::STATUS_DRAFT, 'Complete snapshot creation failed.');
    repositoryAssert($created->name === $approvedSnapshot->name, 'Returned snapshot name did not match the approved snapshot.');
    repositoryAssert($created->definition()->name === $approvedSnapshot->name, 'Returned definition name did not match the approved snapshot.');
    repositoryAssert(isset($created->definition()->programs['DELTA']) && count($created->definition()->questions) === 2, 'Pre-commit hydration failed.');
    assertSnapshotGraphCounts(
        snapshotGraphCounts($fixturePdo, $quizA),
        ['versions' => 1, 'memberships' => 4, 'questions' => 2, 'options' => 4, 'weights' => 4],
        'Primary snapshot',
    );
    repositoryAssert((int) $fixturePdo->query('SELECT COUNT(*) FROM quiz_version_programs WHERE quiz_version_id = ' . $created->id)->fetchColumn() === 4, 'Explicit membership is incomplete.');
    $membershipStatement = $fixturePdo->prepare('SELECT p.short_name FROM quiz_version_programs AS qvp INNER JOIN programs AS p ON p.id = qvp.program_id WHERE qvp.quiz_version_id = :quiz_version_id ORDER BY p.short_name ASC');
    $membershipStatement->execute(['quiz_version_id' => $created->id]);
    repositoryAssert($membershipStatement->fetchAll(PDO::FETCH_COLUMN) === ['ALPHA', 'BETA', 'DELTA', 'GAMMA'], 'Version membership codes are incomplete or unexpected.');
    repositoryAssert((int) $fixturePdo->query('SELECT COUNT(*) FROM option_weights AS ow INNER JOIN programs AS p ON p.id = ow.program_id WHERE ow.question_option_id IN (SELECT qo.id FROM question_options AS qo INNER JOIN questions AS q ON q.id = qo.question_id WHERE q.quiz_version_id = ' . $created->id . ") AND p.short_name = 'DELTA'")->fetchColumn() === 0, 'DELTA weight was fabricated.');
    foreach ([0.333, 1.257, 0.000001, -1.25] as $weight) {
        $found = false;
        foreach ($created->definition()->questions as $question) foreach ($question['options'] as $option) foreach ($option['weights'] as $assignment) if (binary64((float) $assignment['weight']) === binary64($weight)) $found = true;
        repositoryAssert($found, 'Binary64 weight did not hydrate.');
    }
    repositoryAssert($repository->findByQuizAndVersion($quizA, 1)?->id === $created->id && $repository->findByQuizAndVersion($quizA, 999) === null && $repository->findById($created->id)?->id === $created->id, 'Exact lookup failed.');
    repositoryAssert($repository->findDraftForQuiz($quizA)?->id === $created->id, 'Draft lookup failed.');
    $alphaMembership = (int) $fixturePdo->query("SELECT qvp.program_id FROM quiz_version_programs AS qvp INNER JOIN programs AS p ON p.id = qvp.program_id WHERE qvp.quiz_version_id = {$created->id} AND p.short_name = 'ALPHA'")->fetchColumn();
    repositoryAssert($alphaMembership === $programIds[$schoolA . ':ALPHA'] && $alphaMembership !== $programIds[$schoolB . ':ALPHA'], 'Same-school ALPHA was not selected.');
    $beforeSecond = [
        'versions' => (int) $fixturePdo->query("SELECT COUNT(*) FROM quiz_versions WHERE quiz_id = {$quizA}")->fetchColumn(),
        'memberships' => (int) $fixturePdo->query("SELECT COUNT(*) FROM quiz_version_programs WHERE quiz_version_id = {$created->id}")->fetchColumn(),
        'questions' => (int) $fixturePdo->query("SELECT COUNT(*) FROM questions WHERE quiz_version_id = {$created->id}")->fetchColumn(),
        'options' => (int) $fixturePdo->query("SELECT COUNT(*) FROM question_options AS qo INNER JOIN questions AS q ON q.id = qo.question_id WHERE q.quiz_version_id = {$created->id}")->fetchColumn(),
        'weights' => (int) $fixturePdo->query("SELECT COUNT(*) FROM option_weights AS ow INNER JOIN question_options AS qo ON qo.id = ow.question_option_id INNER JOIN questions AS q ON q.id = qo.question_id WHERE q.quiz_version_id = {$created->id}")->fetchColumn(),
    ];
    repositoryRuntime(fn() => $repository->createDraftSnapshot($quizA, testDefinition(88)), 'Second draft was accepted.');
    repositoryAssert($beforeSecond['versions'] === (int) $fixturePdo->query("SELECT COUNT(*) FROM quiz_versions WHERE quiz_id = {$quizA}")->fetchColumn() && $beforeSecond['memberships'] === (int) $fixturePdo->query("SELECT COUNT(*) FROM quiz_version_programs WHERE quiz_version_id = {$created->id}")->fetchColumn() && $beforeSecond['questions'] === (int) $fixturePdo->query("SELECT COUNT(*) FROM questions WHERE quiz_version_id = {$created->id}")->fetchColumn() && $beforeSecond['options'] === (int) $fixturePdo->query("SELECT COUNT(*) FROM question_options AS qo INNER JOIN questions AS q ON q.id = qo.question_id WHERE q.quiz_version_id = {$created->id}")->fetchColumn() && $beforeSecond['weights'] === (int) $fixturePdo->query("SELECT COUNT(*) FROM option_weights AS ow INNER JOIN question_options AS qo ON qo.id = ow.question_option_id INNER JOIN questions AS q ON q.id = qo.question_id WHERE q.quiz_version_id = {$created->id}")->fetchColumn(), 'Second draft created partial rows.');
    $insertQuiz->execute(['school_id' => $schoolA, 'name' => 'Foreign quiz']); $foreignQuiz = (int) $fixturePdo->lastInsertId();
    $foreignDefinition = foreignSchoolDefinition();
    $foreignDefinition->validate();
    $foreignGraph = snapshotGraphCounts($fixturePdo, $foreignQuiz);
    assertSnapshotGraphCounts(
        $foreignGraph,
        ['versions' => 0, 'memberships' => 0, 'questions' => 0, 'options' => 0, 'weights' => 0],
        'Foreign-school fixture before rejection',
    );
    repositoryRuntime(fn() => $repository->createDraftSnapshot($foreignQuiz, $foreignDefinition), 'Foreign-school program was accepted.');
    assertSnapshotGraphCounts(
        snapshotGraphCounts($fixturePdo, $foreignQuiz),
        $foreignGraph,
        'Foreign-school rejection',
    );
    $weightId = (int) $fixturePdo->query("SELECT ow.id FROM option_weights AS ow INNER JOIN question_options AS qo ON qo.id = ow.question_option_id INNER JOIN questions AS q ON q.id = qo.question_id INNER JOIN programs AS p ON p.id = ow.program_id WHERE q.quiz_version_id = {$created->id} AND p.short_name = 'ALPHA' LIMIT 1")->fetchColumn();
    $originalWeightProgram = $programIds[$schoolA . ':ALPHA'];
    foreach ([$programIds[$schoolB . ':ALPHA'], $programIds[$schoolA . ':EPSILON']] as $corruptProgram) {
        $fixturePdo->exec("UPDATE option_weights SET program_id = {$corruptProgram} WHERE id = {$weightId}");
        try { repositoryRuntime(fn() => $repository->findById($created->id), 'Corrupt weight hydrated.'); } finally { $fixturePdo->exec("UPDATE option_weights SET program_id = {$originalWeightProgram} WHERE id = {$weightId}"); }
    }
    $membershipId = (int) $fixturePdo->query("SELECT id FROM quiz_version_programs WHERE quiz_version_id = {$created->id} AND program_id = {$originalWeightProgram}")->fetchColumn();
    $fixturePdo->exec("UPDATE quiz_version_programs SET program_id = {$programIds[$schoolB . ':ALPHA']} WHERE id = {$membershipId}");
    try { repositoryRuntime(fn() => $repository->findById($created->id), 'Cross-school membership hydrated.'); } finally { $fixturePdo->exec("UPDATE quiz_version_programs SET program_id = {$originalWeightProgram} WHERE id = {$membershipId}"); }
    $insertQuiz->execute(['school_id' => $schoolA, 'name' => 'Multiple drafts']); $multiQuiz = (int) $fixturePdo->lastInsertId();
    $fixturePdo->exec("INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at) VALUES ({$multiQuiz}, 1, 'draft', 'One', UTC_TIMESTAMP()), ({$multiQuiz}, 2, 'draft', 'Two', UTC_TIMESTAMP())");
    repositoryRuntime(fn() => $repository->findDraftForQuiz($multiQuiz), 'Multiple drafts were accepted.');
    $insertQuiz->execute(['school_id' => $schoolA, 'name' => 'Rollback']); $rollbackQuiz = (int) $fixturePdo->lastInsertId();
    $rollbackGraph = snapshotGraphCounts($fixturePdo, $rollbackQuiz);
    assertSnapshotGraphCounts(
        $rollbackGraph,
        ['versions' => 0, 'memberships' => 0, 'questions' => 0, 'options' => 0, 'weights' => 0],
        'Rollback fixture before failure',
    );
    $fixturePdo->exec("CREATE TRIGGER fail_repository_weight BEFORE INSERT ON option_weights FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced child failure'");
    try { repositoryRuntime(fn() => $repository->createDraftSnapshot($rollbackQuiz, testDefinition()), 'Forced child failure did not throw.'); } finally { $fixturePdo->exec('DROP TRIGGER fail_repository_weight'); }
    assertSnapshotGraphCounts(
        snapshotGraphCounts($fixturePdo, $rollbackQuiz),
        $rollbackGraph,
        'Forced child failure rollback',
    );
    $insertQuiz->execute(['school_id' => $schoolA, 'name' => 'MAX']); $maxQuiz = (int) $fixturePdo->lastInsertId();
    $first = $repository->createDraftSnapshot($maxQuiz, testDefinition());
    repositoryAssert($repository->updateStatus($first->id, 'draft', 'discarded') === true, 'Draft could not be discarded.');
    $fixturePdo->exec("INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at) VALUES ({$maxQuiz}, 3, 'discarded', 'Gap fixture', UTC_TIMESTAMP())");
    $next = $repository->createDraftSnapshot($maxQuiz, testDefinition(77));
    repositoryAssert($next->versionNumber === 4, 'MAX+1 did not preserve the gap.');
    $insertQuiz->execute(['school_id' => $schoolA, 'name' => 'UTC status']);
    $utcQuiz = (int) $fixturePdo->lastInsertId();
    $utcVersion = $repository->createDraftSnapshot($utcQuiz, testDefinition());
    repositoryAssert(
        (string) $fixturePdo->query("SELECT status FROM quiz_versions WHERE id = {$utcVersion->id}")->fetchColumn() === QuizVersion::STATUS_DRAFT,
        'UTC publication fixture was not draft.',
    );
    $publishedAt = new DateTimeImmutable('2026-09-30 10:15:30', new DateTimeZone('Asia/Jakarta'));
    repositoryAssert(
        $repository->updateStatus(
            $utcVersion->id,
            QuizVersion::STATUS_DRAFT,
            QuizVersion::STATUS_PUBLISHED,
            $publishedAt,
        ),
        'Valid publish failed.',
    );
    repositoryAssert(
        (string) $fixturePdo->query("SELECT published_at FROM quiz_versions WHERE id = {$utcVersion->id}")->fetchColumn() === $publishedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'Publication timestamp was not UTC.',
    );
    $insertQuiz->execute(['school_id' => $schoolA, 'name' => 'Lock']); $lockQuiz = (int) $fixturePdo->lastInsertId();
    $lockPdo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=' . TEST_DATABASE . ';charset=utf8mb4',
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $lockPdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $lockGraph = snapshotGraphCounts($fixturePdo, $lockQuiz);
    assertSnapshotGraphCounts(
        $lockGraph,
        ['versions' => 0, 'memberships' => 0, 'questions' => 0, 'options' => 0, 'weights' => 0],
        'Parent-lock fixture before contention',
    );
    $lockPdo->beginTransaction();
    try {
        $lockPdo->query("SELECT id FROM quizzes WHERE id = {$lockQuiz} FOR UPDATE");
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $lockTimeoutObserved = false;

        try {
            $repository->createDraftSnapshot($lockQuiz, testDefinition());
        } catch (PDOException $exception) {
            $errorInfo = $exception->errorInfo;
            $nativeErrorCode = is_array($errorInfo) && array_key_exists(1, $errorInfo)
                ? (int) $errorInfo[1]
                : null;
            repositoryAssert(
                $nativeErrorCode === 1205 && str_contains($exception->getMessage(), 'Lock wait timeout exceeded'),
                'Parent lock failed for a reason other than a MariaDB lock wait timeout.',
            );
            $lockTimeoutObserved = true;
        }

        repositoryAssert($lockTimeoutObserved, 'Parent lock did not produce a MariaDB lock wait timeout.');
    } finally {
        if ($lockPdo->inTransaction()) {
            $lockPdo->rollBack();
        }
    }
    repositoryAssert(!$pdo->inTransaction(), 'Repository connection remained in a transaction after parent-lock failure.');
    assertSnapshotGraphCounts(
        snapshotGraphCounts($fixturePdo, $lockQuiz),
        $lockGraph,
        'Parent-lock timeout',
    );
    repositoryInvalid(fn() => $repository->updateStatus($created->id, 'draft', 'published'), 'Published null timestamp was accepted.');
    repositoryInvalid(fn() => $repository->updateStatus($created->id, 'draft', 'discarded', new DateTimeImmutable()), 'Non-published timestamp was accepted.');
    $insertQuiz->execute(['school_id' => $schoolA, 'name' => 'Expected-state mismatch']);
    $mismatchQuiz = (int) $fixturePdo->lastInsertId();
    $mismatchVersion = $repository->createDraftSnapshot($mismatchQuiz, testDefinition());
    repositoryAssert(
        (string) $fixturePdo->query("SELECT status FROM quiz_versions WHERE id = {$mismatchVersion->id}")->fetchColumn() === QuizVersion::STATUS_DRAFT,
        'Expected-state mismatch fixture was not draft before the call.',
    );
    repositoryAssert(
        $repository->updateStatus(
            $mismatchVersion->id,
            QuizVersion::STATUS_PUBLISHED,
            QuizVersion::STATUS_DISCARDED,
            null,
        ) === false,
        'Expected-state mismatch did not return false.',
    );
    repositoryAssert(
        (string) $fixturePdo->query("SELECT status FROM quiz_versions WHERE id = {$mismatchVersion->id}")->fetchColumn() === QuizVersion::STATUS_DRAFT,
        'Expected-state mismatch changed the persisted status.',
    );
    repositoryAssert($repository->hasPersistedUsage($created->id) === false, 'Usage is unexpectedly present.');
    $fixturePdo->prepare('INSERT INTO attempts (quiz_version_id, visitor_uuid, attempt_uuid, status, created_at) VALUES (:version, :visitor, :attempt, :status, UTC_TIMESTAMP())')->execute(['version' => $created->id, 'visitor' => '33333333-3333-3333-3333-333333333333', 'attempt' => '44444444-4444-4444-4444-444444444444', 'status' => 'started']);
    repositoryAssert($repository->hasPersistedUsage($created->id) === true, 'Persisted usage was not detected.');
    echo "Quiz version repository integration tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . TEST_DATABASE);
    }
    putenv('DB_PASSWORD');
}
