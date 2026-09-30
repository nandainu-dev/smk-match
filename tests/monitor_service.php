<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\Config;
use App\Core\Database;
use App\Core\MonitorReadRepository;
use App\Core\MonitorService;
use App\Core\SmartLinkRepository;
use App\Core\SmartLinkService;

const MONITOR_SERVICE_TEST_DATABASE = 'smk_match_g12_r3_test';

function monitorServiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectMonitorServiceInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function expectMonitorServiceRuntime(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

function monitorServiceApplyMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migrationPath) {
        $sql = file_get_contents($migrationPath);
        monitorServiceAssert($sql !== false, 'Migration could not be read: ' . basename($migrationPath));

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @return array{school_id: int, campaign_id: int, batch_id: int, quiz_version_id: int, program_ids: array<string, int>} */
function monitorServiceFixture(PDO $connection): array
{
    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('a1200000-0000-4000-8000-000000000001', 'Monitor Service School', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolId = (int) $connection->lastInsertId();
    $programNames = [
        'ZETA' => 'Zeta Snapshot Name',
        'ALPHA' => 'Alpha Snapshot Name',
        'OMEGA' => 'Omega Snapshot Name',
        'BETA' => 'Beta Snapshot Name',
    ];
    $programIds = [];
    $programStatement = $connection->prepare(
        'INSERT INTO programs (
            school_id, name, short_name, personality_title, mascot_path, description,
            skills_json, created_at, updated_at
        ) VALUES (
            :school_id, :name, :short_name, :personality_title, :mascot_path, :description,
            :skills_json, UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )'
    );
    foreach ($programNames as $code => $name) {
        $programStatement->execute([
            'school_id' => $schoolId,
            'name' => $name,
            'short_name' => $code,
            'personality_title' => $code . ' catalog personality',
            'mascot_path' => '/catalog/' . strtolower($code) . '.png',
            'description' => $code . ' catalog description',
            'skills_json' => '["catalog skill"]',
        ]);
        $programIds[$code] = (int) $connection->lastInsertId();
    }

    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolId}, 'Monitor Service Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, published_at, created_at)
         VALUES ({$quizId}, 1, 'published', 'Monitor Service Version', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizVersionId = (int) $connection->lastInsertId();
    $membershipStatement = $connection->prepare(
        'INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at)
         VALUES (:quiz_version_id, :program_id, UTC_TIMESTAMP())'
    );
    $presentationStatement = $connection->prepare(
        'INSERT INTO quiz_version_program_presentations (
            quiz_version_program_id, program_code_snapshot, program_name_snapshot,
            personality_title_snapshot, mascot_path_snapshot, primary_color_snapshot,
            accent_color_snapshot, tagline_snapshot, description_snapshot, superpower_snapshot,
            skills_snapshot, careers_snapshot, snapshot_provenance, created_at, updated_at
        ) VALUES (
            :membership_id, :code, :name, :title, :mascot_path, :primary_color,
            :accent_color, :tagline, :description, :superpower, :skills, :careers,
            :provenance, UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )'
    );
    foreach ($programNames as $code => $name) {
        $membershipStatement->execute([
            'quiz_version_id' => $quizVersionId,
            'program_id' => $programIds[$code],
        ]);
        $presentationStatement->execute([
            'membership_id' => (int) $connection->lastInsertId(),
            'code' => $code,
            'name' => $name,
            'title' => $code . ' snapshot personality',
            'mascot_path' => '/snapshot/' . strtolower($code) . '.png',
            'primary_color' => '#123456',
            'accent_color' => '#abcdef',
            'tagline' => $code . ' snapshot tagline',
            'description' => $code . ' snapshot description',
            'superpower' => $code . ' snapshot superpower',
            'skills' => '["snapshot skill"]',
            'careers' => '["snapshot career"]',
            'provenance' => 'version_snapshot',
        ]);
    }

    $connection->exec(
        "INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at)
         VALUES ({$schoolId}, {$quizId}, {$quizVersionId}, 'Monitor Service Campaign', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $campaignId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO campaign_batches (
            campaign_id, batch_number, quiz_version_id, label, status, active_marker,
            started_at, created_at, updated_at
        ) VALUES (
            {$campaignId}, 3, {$quizVersionId}, 'Morning batch', 'active', 1,
            '2026-10-01 08:00:00', UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )"
    );

    return [
        'school_id' => $schoolId,
        'campaign_id' => $campaignId,
        'batch_id' => (int) $connection->lastInsertId(),
        'quiz_version_id' => $quizVersionId,
        'program_ids' => $programIds,
    ];
}

function monitorServiceInsertResult(
    PDO $connection,
    array $fixture,
    int $suffix,
    ?int $dominantProgramId,
    bool $isTie,
    array $scores,
    array $tiedProgramIds,
    string $submittedAt,
): int {
    $connection->exec(
        "INSERT INTO participants (
            school_id, public_uuid, full_name, origin_school, class_name, phone,
            marketing_consent, marketing_consent_at, created_at, updated_at
        ) VALUES (
            {$fixture['school_id']}, 'a1300000-0000-4000-8000-" . sprintf('%012d', $suffix) . "',
            'Monitor Student {$suffix}', 'Private Origin {$suffix}', 'Class {$suffix}', '081234{$suffix}',
            1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )"
    );
    $participantId = (int) $connection->lastInsertId();
    $attemptStatement = $connection->prepare(
        'INSERT INTO attempts (
            participant_id, campaign_id, campaign_batch_id, quiz_version_id,
            visitor_uuid, attempt_uuid, source, status, submitted_at, created_at
        ) VALUES (
            :participant_id, :campaign_id, :batch_id, :quiz_version_id,
            :visitor_uuid, :attempt_uuid, :source, :status, :submitted_at, UTC_TIMESTAMP()
        )'
    );
    $attemptStatement->execute([
        'participant_id' => $participantId,
        'campaign_id' => $fixture['campaign_id'],
        'batch_id' => $fixture['batch_id'],
        'quiz_version_id' => $fixture['quiz_version_id'],
        'visitor_uuid' => sprintf('a1400000-0000-4000-8000-%012d', $suffix),
        'attempt_uuid' => sprintf('a1500000-0000-4000-8000-%012d', $suffix),
        'source' => 'monitor-service-test',
        'status' => 'completed',
        'submitted_at' => $submittedAt,
    ]);
    $attemptId = (int) $connection->lastInsertId();
    $resultStatement = $connection->prepare(
        'INSERT INTO results (attempt_id, total_raw_score, dominant_program_id, is_tie, created_at)
         VALUES (:attempt_id, 100, :dominant_program_id, :is_tie, UTC_TIMESTAMP())'
    );
    $resultStatement->execute([
        'attempt_id' => $attemptId,
        'dominant_program_id' => $dominantProgramId,
        'is_tie' => $isTie ? 1 : 0,
    ]);
    $resultId = (int) $connection->lastInsertId();
    $scoreStatement = $connection->prepare(
        'INSERT INTO result_scores (
            result_id, program_id, raw_score, normalized_percentage, display_order, created_at
        ) VALUES (
            :result_id, :program_id, :raw_score, :normalized_percentage, :display_order, UTC_TIMESTAMP()
        )'
    );
    foreach ($scores as $displayOrder => $score) {
        $scoreStatement->execute([
            'result_id' => $resultId,
            'program_id' => $score['program_id'],
            'raw_score' => $score['percentage'],
            'normalized_percentage' => $score['percentage'],
            'display_order' => $displayOrder + 1,
        ]);
    }
    $tiedStatement = $connection->prepare(
        'INSERT INTO result_tied_programs (result_id, program_id) VALUES (:result_id, :program_id)'
    );
    foreach ($tiedProgramIds as $programId) {
        $tiedStatement->execute(['result_id' => $resultId, 'program_id' => $programId]);
    }

    return $resultId;
}

/** @param array<string, mixed> $value */
function monitorServiceContainsForbiddenKey(array $value): bool
{
    $forbiddenKeys = [
        'phone', 'marketing_consent', 'marketing_consent_at', 'public_uuid',
        'visitor_uuid', 'attempt_uuid', 'origin_school', 'class_name',
        'raw_score', 'total_raw_score', 'campaign_id', 'quiz_version_id',
    ];
    foreach ($value as $key => $item) {
        if (is_string($key) && in_array($key, $forbiddenKeys, true)) {
            return true;
        }
        if (is_array($item) && monitorServiceContainsForbiddenKey($item)) {
            return true;
        }
    }

    return false;
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

monitorServiceAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === MONITOR_SERVICE_TEST_DATABASE,
    'Unsafe test database configuration.',
);
monitorServiceAssert(
    is_string($username) && $username !== '' && is_string($password),
    'Local database credentials are required.',
);

$admin = null;
try {
    $admin = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $admin->exec('DROP DATABASE IF EXISTS ' . MONITOR_SERVICE_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . MONITOR_SERVICE_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $database = new Database(Config::load(SMK_MATCH_ROOT));
    $connection = $database->connection();
    monitorServiceApplyMigrations($connection);
    $fixture = monitorServiceFixture($connection);
    $smartLinks = new SmartLinkService(
        new SmartLinkRepository($database),
        new CampaignRepository($database),
        new CampaignBatchRepository($database),
    );
    $smartLinks->create($fixture['school_id'], $fixture['campaign_id'], 'Monitor Link', 'monitor-link');
    (new SmartLinkRepository($database))->create(
        $fixture['school_id'],
        $fixture['campaign_id'],
        'Disabled Monitor Link',
        'disabled-monitor-link',
        null,
        false,
    );
    $service = new MonitorService($smartLinks, new MonitorReadRepository($database));

    $zeroState = $service->read('monitor-link');
    monitorServiceAssert(
        $zeroState->batch === [
            'id' => $fixture['batch_id'],
            'number' => 3,
            'label' => 'Morning batch',
            'started_at' => '2026-10-01 08:00:00',
        ]
        && $zeroState->summary === ['started_count' => 0, 'completed_count' => 0, 'tie_count' => 0]
        && count($zeroState->programs) === 4
        && $zeroState->recentActivity === [],
        'Active batch or zero-state projection is invalid.',
    );
    foreach ($zeroState->programs as $program) {
        monitorServiceAssert(
            $program['dominant_count'] === 0 && $program['score_average_percentage'] === 0.0,
            'Zero-state program metrics are invalid.',
        );
    }
    expectMonitorServiceInvalid(
        static fn() => $service->read('monitor-link', 0),
        'Zero recent limit was accepted.',
    );
    expectMonitorServiceInvalid(
        static fn() => $service->read('monitor-link', 51),
        'Oversized recent limit was accepted.',
    );
    expectMonitorServiceInvalid(
        static fn() => $service->read('INVALID LINK'),
        'Invalid alias was accepted.',
    );
    expectMonitorServiceRuntime(
        static fn() => $service->read('disabled-monitor-link'),
        'Inactive alias was accepted.',
    );

    $programIds = $fixture['program_ids'];
    monitorServiceInsertResult($connection, $fixture, 1, $programIds['ALPHA'], false, [
        ['program_id' => $programIds['ALPHA'], 'percentage' => 60.0],
        ['program_id' => $programIds['ZETA'], 'percentage' => 20.0],
        ['program_id' => $programIds['OMEGA'], 'percentage' => 10.0],
        ['program_id' => $programIds['BETA'], 'percentage' => 10.0],
    ], [], '2026-10-01 08:05:00');
    $tieResultId = monitorServiceInsertResult($connection, $fixture, 2, null, true, [
        ['program_id' => $programIds['OMEGA'], 'percentage' => 40.0],
        ['program_id' => $programIds['ZETA'], 'percentage' => 40.0],
        ['program_id' => $programIds['ALPHA'], 'percentage' => 10.0],
        ['program_id' => $programIds['BETA'], 'percentage' => 10.0],
    ], [$programIds['ZETA'], $programIds['OMEGA']], '2026-10-01 08:06:00');
    $connection->prepare(
        'UPDATE programs
         SET name = :name, personality_title = :title, description = :description
         WHERE id = :id'
    )->execute([
        'name' => 'Mutable Catalog Name',
        'title' => 'Mutable Catalog Personality',
        'description' => 'Mutable Catalog Description',
        'id' => $programIds['ZETA'],
    ]);

    $snapshot = $service->read('monitor-link', 1);
    $payload = $snapshot->toArray();
    monitorServiceAssert(
        $snapshot->alias === 'monitor-link'
        && $snapshot->summary === ['started_count' => 2, 'completed_count' => 2, 'tie_count' => 1]
        && count($snapshot->programs) === 4
        && count($snapshot->recentActivity) === 1
        && $snapshot->recentActivity[0]['result_id'] === $tieResultId,
        'Alias orchestration or recent-limit forwarding is invalid.',
    );
    $programByCode = [];
    foreach ($snapshot->programs as $program) {
        $programByCode[$program['code']] = $program;
    }
    monitorServiceAssert(
        $programByCode['ZETA']['name'] === 'Zeta Snapshot Name'
        && $programByCode['ZETA']['personality_title'] === 'ZETA snapshot personality'
        && $programByCode['ZETA']['description'] === 'ZETA snapshot description',
        'Monitor projection depends on mutable catalog fields.',
    );
    $tieEvent = $snapshot->recentActivity[0];
    monitorServiceAssert(
        $tieEvent['outcome']['kind'] === 'tie'
        && $tieEvent['outcome']['message_key'] === 'monitor.tie'
        && $tieEvent['outcome']['dominant_program'] === null
        && array_map(static fn(array $program): string => $program['code'], $tieEvent['outcome']['tied_programs']) === ['OMEGA', 'ZETA']
        && array_map(static fn(array $ranking): int => $ranking['display_order'], $tieEvent['ranking']) === [1, 2, 3, 4]
        && array_map(static fn(array $ranking): string => $ranking['program']['code'], $tieEvent['ranking']) === ['OMEGA', 'ZETA', 'ALPHA', 'BETA'],
        'Tie safety or persisted ranking order is invalid.',
    );
    $decisive = $service->read('monitor-link', 2)->recentActivity[1];
    monitorServiceAssert(
        $decisive['outcome']['kind'] === 'decisive'
        && $decisive['outcome']['message_key'] === 'monitor.decisive'
        && $decisive['outcome']['dominant_program']['code'] === 'ALPHA'
        && $decisive['outcome']['tied_programs'] === [],
        'Decisive outcome projection is invalid.',
    );
    monitorServiceAssert(
        !monitorServiceContainsForbiddenKey($payload),
        'Monitor payload exposes a forbidden sensitive or internal field.',
    );
    monitorServiceAssert(
        !array_key_exists('after_result_id', $payload),
        'Monitor payload added a strict result cursor.',
    );
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . MONITOR_SERVICE_TEST_DATABASE);
    }
    putenv('DB_PASSWORD');
}

echo "Monitor service tests passed.\n";
