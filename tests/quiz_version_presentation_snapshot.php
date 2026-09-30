<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\QuizDefinition;
use App\Core\QuizVersionProgramPresentationRepository;
use App\Core\QuizVersionProgramRepository;
use App\Core\QuizVersionRepository;
use App\Core\QuizVersionService;

const PRESENTATION_SNAPSHOT_TEST_DATABASE = 'smk_match_g11_r12a2_snapshot_test';

function presentationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function presentationMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $sql = file_get_contents($path);
        presentationAssert($sql !== false, 'Could not read migration.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

function presentationDefinition(string $name, array $programCodes): QuizDefinition
{
    $programs = array_fill_keys($programCodes, true);
    $weights = [];
    foreach ($programCodes as $index => $code) {
        $weights[] = ['program' => $code, 'weight' => (float) ($index + 1)];
    }

    return new QuizDefinition($name, 1, $programs, [[
        'id' => 'question-1',
        'text' => 'Presentation snapshot question',
        'order' => 1,
        'options' => [
            ['id' => 'option-1', 'text' => 'First option', 'order' => 1, 'weights' => $weights],
            ['id' => 'option-2', 'text' => 'Second option', 'order' => 2, 'weights' => $weights],
        ],
    ]]);
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
presentationAssert($host === '127.0.0.1' && $port === '3306' && $databaseName === PRESENTATION_SNAPSHOT_TEST_DATABASE, 'Unsafe test database configuration.');
presentationAssert(is_string($user) && $user !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $admin->exec('DROP DATABASE IF EXISTS ' . PRESENTATION_SNAPSHOT_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . PRESENTATION_SNAPSHOT_TEST_DATABASE . ' CHARACTER SET utf8mb4');
    $database = new Database(Config::load(SMK_MATCH_ROOT));
    $connection = $database->connection();
    presentationMigrations($connection);
    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('19191919-1919-4191-8191-191919191919', 'Snapshot School', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolId = (int) $connection->lastInsertId();
    $programInsert = $connection->prepare('INSERT INTO programs (school_id, name, short_name, personality_title, mascot_path, description, skills_json, created_at, updated_at) VALUES (:school, :name, :code, :title, :mascot, :description, :skills, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    foreach (['ALPHA', 'BETA', 'GAMMA'] as $index => $code) {
        $programInsert->execute(['school' => $schoolId, 'name' => 'Original ' . $code, 'code' => $code, 'title' => 'Title ' . $code, 'mascot' => '/assets/mascots/' . strtolower($code) . '.png', 'description' => 'Description ' . $code, 'skills' => '["Skill ' . $code . '"]']);
    }
    $connection->exec("INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES ({$schoolId}, 'Snapshot Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $quizId = (int) $connection->lastInsertId();
    $presentations = new QuizVersionProgramPresentationRepository($database);
    $versions = new QuizVersionRepository($database, $presentations);
    $service = new QuizVersionService($versions);
    $source = $service->getOrCreateDraft($quizId, presentationDefinition('Snapshot Quiz', ['ALPHA', 'BETA', 'GAMMA']));
    $sourceSnapshots = $presentations->findAllByQuizVersionId($source->id);
    presentationAssert(count($sourceSnapshots) === 3, 'New draft must create one snapshot per membership.');
    foreach ($sourceSnapshots as $snapshot) {
        presentationAssert($snapshot->snapshotProvenance === 'version_snapshot' && $snapshot->primaryColorSnapshot === null && $snapshot->accentColorSnapshot === null && $snapshot->taglineSnapshot === null && $snapshot->superpowerSnapshot === null && $snapshot->careersSnapshot === null, 'New draft snapshot has invalid provenance or unavailable fields.');
    }
    $alpha = array_values(array_filter($sourceSnapshots, static fn($snapshot): bool => $snapshot->programCodeSnapshot === 'ALPHA'))[0];
    presentationAssert($alpha->programNameSnapshot === 'Original ALPHA' && $alpha->personalityTitleSnapshot === 'Title ALPHA' && $alpha->mascotPathSnapshot === '/assets/mascots/alpha.png' && $alpha->descriptionSnapshot === 'Description ALPHA' && $alpha->skillsSnapshot === '["Skill ALPHA"]', 'Current catalog mapping was not snapshotted exactly.');

    $legacyMembershipStatement = $connection->prepare(
        'SELECT qvp.id
         FROM quiz_version_programs AS qvp
         INNER JOIN programs AS p ON p.id = qvp.program_id
         WHERE qvp.quiz_version_id = :version_id AND p.short_name = :program_code'
    );
    $legacyMembershipStatement->execute([
        'version_id' => $source->id,
        'program_code' => 'BETA',
    ]);
    $legacyMembershipId = (int) $legacyMembershipStatement->fetchColumn();
    presentationAssert($legacyMembershipId > 0, 'Legacy presentation membership could not be located.');

    $legacySnapshot = [
        'code' => 'LEGACY-BETA',
        'name' => 'Frozen Legacy Beta',
        'title' => 'Frozen Legacy Title',
        'mascot' => '/assets/mascots/legacy-beta.png',
        'description' => 'Frozen Legacy Description',
        'skills' => '["Frozen Legacy Skill"]',
        'provenance' => 'legacy_current_catalog',
    ];
    $legacySnapshotStatement = $connection->prepare(
        'UPDATE quiz_version_program_presentations
         SET program_code_snapshot = :code,
             program_name_snapshot = :name,
             personality_title_snapshot = :title,
             mascot_path_snapshot = :mascot,
             description_snapshot = :description,
             skills_snapshot = :skills,
             snapshot_provenance = :provenance
         WHERE quiz_version_program_id = :membership_id'
    );
    $legacySnapshotStatement->execute($legacySnapshot + ['membership_id' => $legacyMembershipId]);
    $sourceSnapshots = $presentations->findAllByQuizVersionId($source->id);

    $connection->exec("UPDATE programs SET short_name = 'ALPHA-NEW', name = 'Changed ALPHA', personality_title = 'Changed', mascot_path = '/assets/mascots/changed.png', description = 'Changed', skills_json = '[\"Changed\"]' WHERE school_id = {$schoolId} AND short_name = 'ALPHA'");
    $connection->exec("UPDATE programs SET short_name = 'BETA-LIVE', name = 'Changed Live Beta', personality_title = 'Changed Live Title', mascot_path = '/assets/mascots/changed-live-beta.png', description = 'Changed Live Description', skills_json = '[\"Changed Live Skill\"]' WHERE school_id = {$schoolId} AND short_name = 'BETA'");
    $reloaded = $versions->findById($source->id);
    presentationAssert($reloaded !== null && isset($reloaded->definition()->programs['ALPHA']) && !isset($reloaded->definition()->programs['ALPHA-NEW']), 'Historical version code changed after a global program edit.');
    $historicalMap = (new QuizVersionProgramRepository($database))->findProgramIdsByVersionId($source->id);
    presentationAssert(isset($historicalMap['ALPHA']) && !isset($historicalMap['ALPHA-NEW']), 'Historical program mapping used mutable program code.');
    presentationAssert($reloaded !== null && isset($reloaded->definition()->programs['LEGACY-BETA']) && !isset($reloaded->definition()->programs['BETA-LIVE']), 'Historical read did not use the frozen legacy presentation code.');
    presentationAssert(isset($historicalMap['LEGACY-BETA']) && !isset($historicalMap['BETA-LIVE']), 'Historical program mapping did not preserve the legacy presentation code.');
    $connection->exec("UPDATE quiz_versions SET status = 'published', published_at = UTC_TIMESTAMP() WHERE id = {$source->id}");
    $clone = $service->cloneVersionToDraft($quizId, $source->id);
    $cloneSnapshots = $presentations->findAllByQuizVersionId($clone->id);
    presentationAssert(count($cloneSnapshots) === 3, 'Clone snapshot membership count is invalid.');
    $sourceByCode = []; foreach ($sourceSnapshots as $snapshot) { $sourceByCode[$snapshot->programCodeSnapshot] = $snapshot; }
    foreach ($cloneSnapshots as $snapshot) {
        $original = $sourceByCode[$snapshot->programCodeSnapshot] ?? null;
        presentationAssert($original !== null && $snapshot->programNameSnapshot === $original->programNameSnapshot && $snapshot->personalityTitleSnapshot === $original->personalityTitleSnapshot && $snapshot->mascotPathSnapshot === $original->mascotPathSnapshot && $snapshot->descriptionSnapshot === $original->descriptionSnapshot && $snapshot->skillsSnapshot === $original->skillsSnapshot && $snapshot->snapshotProvenance === 'version_snapshot', 'Clone did not copy source presentation exactly.');
    }
    $legacyRowStatement = $connection->prepare(
        'SELECT program_code_snapshot, program_name_snapshot, personality_title_snapshot,
                mascot_path_snapshot, description_snapshot, skills_snapshot, snapshot_provenance
         FROM quiz_version_program_presentations
         WHERE quiz_version_program_id = :membership_id'
    );
    $legacyRowStatement->execute(['membership_id' => $legacyMembershipId]);
    $legacyRow = $legacyRowStatement->fetch(PDO::FETCH_ASSOC);
    presentationAssert(is_array($legacyRow) && $legacyRow['program_code_snapshot'] === $legacySnapshot['code'], 'Legacy program code snapshot was rewritten.');
    presentationAssert($legacyRow['program_name_snapshot'] === $legacySnapshot['name'], 'Legacy program name snapshot was rewritten.');
    presentationAssert($legacyRow['personality_title_snapshot'] === $legacySnapshot['title'] && $legacyRow['mascot_path_snapshot'] === $legacySnapshot['mascot'] && $legacyRow['description_snapshot'] === $legacySnapshot['description'] && $legacyRow['skills_snapshot'] === $legacySnapshot['skills'], 'Legacy optional presentation fields were rewritten.');
    presentationAssert($legacyRow['snapshot_provenance'] === $legacySnapshot['provenance'], 'Legacy snapshot provenance was rewritten.');
    $connection->exec("UPDATE programs SET short_name = 'BETA', name = 'Original BETA', personality_title = 'Title BETA', mascot_path = '/assets/mascots/beta.png', description = 'Description BETA', skills_json = '[\"Skill BETA\"]' WHERE school_id = {$schoolId} AND short_name = 'BETA-LIVE'");
    $connection->exec("UPDATE quiz_versions SET status = 'discarded' WHERE id = {$clone->id}");
    $connection->exec("DELETE qvpp FROM quiz_version_program_presentations AS qvpp INNER JOIN quiz_version_programs AS qvp ON qvp.id = qvpp.quiz_version_program_id WHERE qvp.quiz_version_id = {$source->id} AND qvpp.program_code_snapshot = 'GAMMA'");
    try { $service->cloneVersionToDraft($quizId, $source->id); throw new RuntimeException('Missing source snapshot was accepted.'); } catch (Throwable) {}
    presentationAssert((int) $connection->query("SELECT COUNT(*) FROM quiz_versions WHERE quiz_id = {$quizId}")->fetchColumn() === 2, 'Missing source snapshot created a fallback draft.');
    $connection->exec("INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES ({$schoolId}, 'Rollback Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $rollbackQuizId = (int) $connection->lastInsertId();
    $connection->exec("UPDATE programs SET mascot_path = 'https://invalid.example/mascot.png' WHERE school_id = {$schoolId} AND short_name = 'BETA'");
    try { $versions->createDraftSnapshot($rollbackQuizId, presentationDefinition('Rollback Quiz', ['BETA'])); throw new RuntimeException('Invalid snapshot was accepted.'); } catch (InvalidArgumentException) {}
    presentationAssert((int) $connection->query("SELECT COUNT(*) FROM quiz_versions WHERE quiz_id = {$rollbackQuizId}")->fetchColumn() === 0, 'Snapshot failure did not roll back the version.');
    echo "Quiz version presentation snapshot tests passed.\n";
} finally {
    if ($admin instanceof PDO) { $admin->exec('DROP DATABASE IF EXISTS ' . PRESENTATION_SNAPSHOT_TEST_DATABASE); }
}
