<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\MonitorReadRepository;

const MONITOR_READ_TEST_DATABASE = 'smk_match_g12_r2_test';

function monitorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function monitorApprox(float $actual, float $expected, string $message): void
{
    monitorAssert(abs($actual - $expected) < 0.000001, $message);
}

function monitorApplyMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migrationPath) {
        $sql = file_get_contents($migrationPath);
        monitorAssert($sql !== false, 'Migration could not be read: ' . basename($migrationPath));

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @return array<string, int> */
function monitorRowCounts(PDO $connection): array
{
    $counts = [];
    foreach (['attempts', 'results', 'result_scores', 'result_tied_programs'] as $table) {
        $counts[$table] = (int) $connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    return $counts;
}

function monitorInsertParticipant(PDO $connection, int $schoolId, int $suffix): int
{
    $statement = $connection->prepare(
        'INSERT INTO participants (
            school_id, public_uuid, full_name, origin_school, class_name, phone,
            marketing_consent, marketing_consent_at, created_at, updated_at
        ) VALUES (
            :school_id, :public_uuid, :full_name, :origin_school, :class_name, :phone,
            1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )'
    );
    $statement->execute([
        'school_id' => $schoolId,
        'public_uuid' => sprintf('12000000-0000-4000-8000-%012d', $suffix),
        'full_name' => 'Monitor Participant ' . $suffix,
        'origin_school' => 'Private Origin ' . $suffix,
        'class_name' => 'X-' . $suffix,
        'phone' => '08123456' . $suffix,
    ]);

    return (int) $connection->lastInsertId();
}

function monitorInsertAttempt(
    PDO $connection,
    int $participantId,
    int $campaignId,
    int $batchId,
    int $quizVersionId,
    int $suffix,
    string $status,
    ?string $submittedAt,
): int {
    $statement = $connection->prepare(
        'INSERT INTO attempts (
            participant_id, campaign_id, campaign_batch_id, quiz_version_id,
            visitor_uuid, attempt_uuid, source, status, submitted_at, created_at
        ) VALUES (
            :participant_id, :campaign_id, :batch_id, :quiz_version_id,
            :visitor_uuid, :attempt_uuid, :source, :status, :submitted_at, UTC_TIMESTAMP()
        )'
    );
    $statement->execute([
        'participant_id' => $participantId,
        'campaign_id' => $campaignId,
        'batch_id' => $batchId,
        'quiz_version_id' => $quizVersionId,
        'visitor_uuid' => sprintf('13000000-0000-4000-8000-%012d', $suffix),
        'attempt_uuid' => sprintf('14000000-0000-4000-8000-%012d', $suffix),
        'source' => 'monitor-test',
        'status' => $status,
        'submitted_at' => $submittedAt,
    ]);

    return (int) $connection->lastInsertId();
}

/** @param array<int, array{program_id: int, percentage: float}> $scores */
function monitorInsertResult(
    PDO $connection,
    int $attemptId,
    ?int $dominantProgramId,
    bool $isTie,
    array $scores,
    array $tiedProgramIds = [],
): int {
    $resultStatement = $connection->prepare(
        'INSERT INTO results (attempt_id, total_raw_score, dominant_program_id, is_tie, created_at)
         VALUES (:attempt_id, :total_raw_score, :dominant_program_id, :is_tie, UTC_TIMESTAMP())'
    );
    $resultStatement->execute([
        'attempt_id' => $attemptId,
        'total_raw_score' => 100.0,
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

/** @return array<string, mixed> */
function monitorFixture(PDO $connection): array
{
    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('11000000-0000-4000-8000-000000000001', 'Monitor School', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolId = (int) $connection->lastInsertId();
    $programIds = [];
    $programs = [
        'ZETA' => 'Zeta Snapshot',
        'ALPHA' => 'Alpha Snapshot',
        'OMEGA' => 'Omega Snapshot',
        'BETA' => 'Beta Snapshot',
    ];
    $programStatement = $connection->prepare(
        'INSERT INTO programs (
            school_id, name, short_name, personality_title, description, mascot_path,
            skills_json, created_at, updated_at
        ) VALUES (
            :school_id, :name, :short_name, :personality_title, :description, :mascot_path,
            :skills, UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )'
    );
    foreach ($programs as $code => $name) {
        $programStatement->execute([
            'school_id' => $schoolId,
            'name' => $name,
            'short_name' => $code,
            'personality_title' => $code . ' personality',
            'description' => $code . ' description',
            'mascot_path' => '/assets/mascots/' . strtolower($code) . '.png',
            'skills' => '["' . $code . ' skill"]',
        ]);
        $programIds[$code] = (int) $connection->lastInsertId();
    }

    $connection->exec(
        "INSERT INTO quizzes (school_id, name, created_at, updated_at)
         VALUES ({$schoolId}, 'Monitor Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $quizId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO quiz_versions (quiz_id, version_number, status, name, published_at, created_at)
         VALUES ({$quizId}, 1, 'published', 'Monitor Version', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
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
            :membership_id, :code, :name, :title, :mascot, :primary_color,
            :accent_color, :tagline, :description, :superpower, :skills, :careers,
            :provenance, UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )'
    );
    foreach ($programs as $code => $name) {
        $membershipStatement->execute([
            'quiz_version_id' => $quizVersionId,
            'program_id' => $programIds[$code],
        ]);
        $presentationStatement->execute([
            'membership_id' => (int) $connection->lastInsertId(),
            'code' => $code,
            'name' => $name,
            'title' => $code . ' snapshot personality',
            'mascot' => '/assets/mascots/' . strtolower($code) . '.png',
            'primary_color' => '#123456',
            'accent_color' => '#abcdef',
            'tagline' => $code . ' snapshot tagline',
            'description' => $code . ' snapshot description',
            'superpower' => $code . ' snapshot superpower',
            'skills' => '["' . $code . ' snapshot skill"]',
            'careers' => '["' . $code . ' snapshot career"]',
            'provenance' => 'version_snapshot',
        ]);
    }

    $campaignStatement = $connection->prepare(
        'INSERT INTO campaigns (
            school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at
        ) VALUES (
            :school_id, :quiz_id, :quiz_version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )'
    );
    $campaignStatement->execute([
        'school_id' => $schoolId,
        'quiz_id' => $quizId,
        'quiz_version_id' => $quizVersionId,
        'name' => 'Campaign A',
        'status' => 'active',
    ]);
    $campaignA = (int) $connection->lastInsertId();
    $campaignStatement->execute([
        'school_id' => $schoolId,
        'quiz_id' => $quizId,
        'quiz_version_id' => $quizVersionId,
        'name' => 'Campaign B',
        'status' => 'active',
    ]);
    $campaignB = (int) $connection->lastInsertId();
    $batchStatement = $connection->prepare(
        'INSERT INTO campaign_batches (
            campaign_id, batch_number, quiz_version_id, status, active_marker,
            started_at, closed_at, created_at, updated_at
        ) VALUES (
            :campaign_id, :batch_number, :quiz_version_id, :status, :active_marker,
            UTC_TIMESTAMP(), :closed_at, UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )'
    );
    $batchStatement->execute([
        'campaign_id' => $campaignA,
        'batch_number' => 1,
        'quiz_version_id' => $quizVersionId,
        'status' => 'closed',
        'active_marker' => null,
        'closed_at' => '2026-10-01 08:00:00',
    ]);
    $archivedBatch = (int) $connection->lastInsertId();
    $batchStatement->execute([
        'campaign_id' => $campaignA,
        'batch_number' => 2,
        'quiz_version_id' => $quizVersionId,
        'status' => 'active',
        'active_marker' => 1,
        'closed_at' => null,
    ]);
    $activeBatch = (int) $connection->lastInsertId();
    $batchStatement->execute([
        'campaign_id' => $campaignB,
        'batch_number' => 1,
        'quiz_version_id' => $quizVersionId,
        'status' => 'active',
        'active_marker' => 1,
        'closed_at' => null,
    ]);
    $campaignBBatch = (int) $connection->lastInsertId();

    return [
        'school_id' => $schoolId,
        'program_ids' => $programIds,
        'quiz_version_id' => $quizVersionId,
        'campaign_a' => $campaignA,
        'campaign_b' => $campaignB,
        'archived_batch' => $archivedBatch,
        'active_batch' => $activeBatch,
        'campaign_b_batch' => $campaignBBatch,
    ];
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

monitorAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === MONITOR_READ_TEST_DATABASE,
    'Unsafe test database configuration.',
);
monitorAssert(
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
    $admin->exec('DROP DATABASE IF EXISTS ' . MONITOR_READ_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . MONITOR_READ_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $database = new Database(Config::load(SMK_MATCH_ROOT));
    $connection = $database->connection();
    monitorApplyMigrations($connection);
    $fixture = monitorFixture($connection);
    $repository = new MonitorReadRepository($database);

    $zeroState = $repository->readActiveBatch($fixture['campaign_a'], 2);
    monitorAssert($zeroState !== null, 'Active batch was not resolved for zero state.');
    monitorAssert(
        $zeroState['batch']['id'] === $fixture['active_batch']
        && count($zeroState['programs']) === 4
        && $zeroState['summary'] === ['started_count' => 0, 'completed_count' => 0, 'tie_count' => 0]
        && $zeroState['recent_events'] === [],
        'Zero-state monitor data is incomplete.',
    );
    foreach ($zeroState['programs'] as $program) {
        monitorAssert(
            $program['dominant_count'] === 0 && $program['score_average_percentage'] === 0.0,
            'Zero-state program metrics are not zero.',
        );
    }
    monitorAssert($repository->readActiveBatch(999999, 1) === null, 'Missing active campaign did not return null.');

    $programIds = $fixture['program_ids'];
    $archivedAttempt = monitorInsertAttempt(
        $connection,
        monitorInsertParticipant($connection, $fixture['school_id'], 1),
        $fixture['campaign_a'],
        $fixture['archived_batch'],
        $fixture['quiz_version_id'],
        1,
        'completed',
        '2026-10-01 09:00:00',
    );
    $archivedResult = monitorInsertResult($connection, $archivedAttempt, $programIds['ZETA'], false, [
        ['program_id' => $programIds['ZETA'], 'percentage' => 100.0],
        ['program_id' => $programIds['ALPHA'], 'percentage' => 0.0],
        ['program_id' => $programIds['OMEGA'], 'percentage' => 0.0],
        ['program_id' => $programIds['BETA'], 'percentage' => 0.0],
    ]);
    $otherCampaignAttempt = monitorInsertAttempt(
        $connection,
        monitorInsertParticipant($connection, $fixture['school_id'], 2),
        $fixture['campaign_b'],
        $fixture['campaign_b_batch'],
        $fixture['quiz_version_id'],
        2,
        'completed',
        '2026-10-01 09:30:00',
    );
    $otherCampaignResult = monitorInsertResult($connection, $otherCampaignAttempt, $programIds['OMEGA'], false, [
        ['program_id' => $programIds['OMEGA'], 'percentage' => 100.0],
        ['program_id' => $programIds['ZETA'], 'percentage' => 0.0],
        ['program_id' => $programIds['ALPHA'], 'percentage' => 0.0],
        ['program_id' => $programIds['BETA'], 'percentage' => 0.0],
    ]);

    $firstAttempt = monitorInsertAttempt(
        $connection, monitorInsertParticipant($connection, $fixture['school_id'], 3),
        $fixture['campaign_a'], $fixture['active_batch'], $fixture['quiz_version_id'], 3,
        'completed', '2026-10-01 10:00:00',
    );
    $firstResult = monitorInsertResult($connection, $firstAttempt, $programIds['BETA'], false, [
        ['program_id' => $programIds['BETA'], 'percentage' => 60.0],
        ['program_id' => $programIds['ZETA'], 'percentage' => 20.0],
        ['program_id' => $programIds['ALPHA'], 'percentage' => 10.0],
        ['program_id' => $programIds['OMEGA'], 'percentage' => 10.0],
    ]);
    $secondAttempt = monitorInsertAttempt(
        $connection, monitorInsertParticipant($connection, $fixture['school_id'], 4),
        $fixture['campaign_a'], $fixture['active_batch'], $fixture['quiz_version_id'], 4,
        'completed', '2026-10-01 10:01:00',
    );
    $secondResult = monitorInsertResult($connection, $secondAttempt, $programIds['ALPHA'], false, [
        ['program_id' => $programIds['ALPHA'], 'percentage' => 50.0],
        ['program_id' => $programIds['ZETA'], 'percentage' => 30.0],
        ['program_id' => $programIds['BETA'], 'percentage' => 10.0],
        ['program_id' => $programIds['OMEGA'], 'percentage' => 10.0],
    ]);
    $tieAttempt = monitorInsertAttempt(
        $connection, monitorInsertParticipant($connection, $fixture['school_id'], 5),
        $fixture['campaign_a'], $fixture['active_batch'], $fixture['quiz_version_id'], 5,
        'completed', '2026-10-01 10:02:00',
    );
    $tieResult = monitorInsertResult($connection, $tieAttempt, null, true, [
        ['program_id' => $programIds['OMEGA'], 'percentage' => 40.0],
        ['program_id' => $programIds['ZETA'], 'percentage' => 40.0],
        ['program_id' => $programIds['ALPHA'], 'percentage' => 10.0],
        ['program_id' => $programIds['BETA'], 'percentage' => 10.0],
    ], [$programIds['ZETA'], $programIds['OMEGA']]);
    monitorInsertAttempt(
        $connection, monitorInsertParticipant($connection, $fixture['school_id'], 6),
        $fixture['campaign_a'], $fixture['active_batch'], $fixture['quiz_version_id'], 6,
        'started', null,
    );
    $connection->prepare(
        'UPDATE programs SET short_name = :short_name, name = :name, description = :description WHERE id = :id'
    )->execute([
        'short_name' => 'MUTATED-ZETA',
        'name' => 'Mutated Zeta Catalog Name',
        'description' => 'Mutated catalog description',
        'id' => $programIds['ZETA'],
    ]);

    $beforeReadCounts = monitorRowCounts($connection);
    $read = $repository->readActiveBatch($fixture['campaign_a'], 2);
    $afterReadCounts = monitorRowCounts($connection);
    monitorAssert($read !== null && $beforeReadCounts === $afterReadCounts, 'Monitor repository changed persistent state.');
    monitorAssert(
        $read['summary'] === ['started_count' => 4, 'completed_count' => 3, 'tie_count' => 1]
        && count($read['recent_events']) === 2
        && $read['recent_events'][0]['result_id'] === $tieResult
        && $read['recent_events'][1]['result_id'] === $secondResult,
        'Active-batch summary or recent event limit/order is incorrect.',
    );
    $metricsByCode = [];
    foreach ($read['programs'] as $program) {
        $metricsByCode[$program['code']] = $program;
    }
    monitorAssert(
        $metricsByCode['ZETA']['name'] === 'Zeta Snapshot'
        && $metricsByCode['ZETA']['tagline'] === 'ZETA snapshot tagline'
        && $metricsByCode['ZETA']['dominant_count'] === 0
        && $metricsByCode['ALPHA']['dominant_count'] === 1
        && $metricsByCode['BETA']['dominant_count'] === 1
        && $metricsByCode['OMEGA']['dominant_count'] === 0,
        'Snapshot identity or dominant-count aggregation is incorrect.',
    );
    monitorApprox($metricsByCode['ZETA']['score_average_percentage'], 30.0, 'ZETA average is incorrect.');
    monitorApprox($metricsByCode['ALPHA']['score_average_percentage'], 70.0 / 3.0, 'ALPHA average is incorrect.');
    monitorApprox($metricsByCode['OMEGA']['score_average_percentage'], 20.0, 'OMEGA average is incorrect.');
    monitorApprox($metricsByCode['BETA']['score_average_percentage'], 80.0 / 3.0, 'BETA average is incorrect.');

    $tieEvent = $read['recent_events'][0];
    monitorAssert(
        $tieEvent['is_tie'] === true
        && $tieEvent['dominant_program'] === null
        && array_map(static fn(array $program): string => $program['code'], $tieEvent['tied_programs']) === ['OMEGA', 'ZETA']
        && array_map(static fn(array $row): int => $row['display_order'], $tieEvent['ranking']) === [1, 2, 3, 4]
        && array_map(static fn(array $row): string => $row['program']['code'], $tieEvent['ranking']) === ['OMEGA', 'ZETA', 'ALPHA', 'BETA'],
        'Tie event does not preserve tie or persisted ranking semantics.',
    );
    $payload = (string) json_encode($read, JSON_THROW_ON_ERROR);
    foreach (['phone', 'marketing_consent', 'marketing_consent_at', 'visitor_uuid', 'public_uuid', 'attempt_uuid', 'answers', 'raw_score'] as $forbiddenKey) {
        monitorAssert(!str_contains($payload, '"' . $forbiddenKey . '"'), 'Monitor payload leaks ' . $forbiddenKey . '.');
    }
    monitorAssert($firstResult > 0, 'Fixture did not create first active result.');

    for ($suffix = 7; $suffix <= 58; $suffix++) {
        $attemptId = monitorInsertAttempt(
            $connection,
            monitorInsertParticipant($connection, $fixture['school_id'], $suffix),
            $fixture['campaign_a'],
            $fixture['active_batch'],
            $fixture['quiz_version_id'],
            $suffix,
            'completed',
            sprintf('2026-10-01 11:%02d:00', $suffix % 60),
        );
        monitorInsertResult($connection, $attemptId, $programIds['ALPHA'], false, [
            ['program_id' => $programIds['ALPHA'], 'percentage' => 100.0],
            ['program_id' => $programIds['ZETA'], 'percentage' => 0.0],
            ['program_id' => $programIds['OMEGA'], 'percentage' => 0.0],
            ['program_id' => $programIds['BETA'], 'percentage' => 0.0],
        ]);
    }

    $beforeReconciliationCounts = monitorRowCounts($connection);
    $firstReconciliationPage = $repository->readActiveBatch($fixture['campaign_a'], 50, 0);
    $afterReconciliationCounts = monitorRowCounts($connection);
    monitorAssert($firstReconciliationPage !== null, 'Reconciliation did not resolve the active batch.');
    monitorAssert($beforeReconciliationCounts === $afterReconciliationCounts, 'Reconciliation changed persistent state.');
    $firstPage = $firstReconciliationPage['reconciliation'];
    $firstPageSortedIds = $firstPage['result_ids'];
    sort($firstPageSortedIds, SORT_NUMERIC);
    monitorAssert(
        count($firstPage['result_ids']) === 50
        && $firstPage['has_more'] === true
        && $firstPage['next_page_after_result_id'] === $firstPage['result_ids'][49]
        && $firstPage['result_ids'] === array_values(array_unique($firstPage['result_ids']))
        && $firstPage['result_ids'] === $firstPageSortedIds,
        'Reconciliation first page is not a bounded ascending ID page.',
    );
    monitorAssert(
        !in_array($archivedResult, $firstPage['result_ids'], true)
        && !in_array($otherCampaignResult, $firstPage['result_ids'], true),
        'Reconciliation exposed attempts outside the active batch.',
    );

    $secondReconciliationPage = $repository->readActiveBatch(
        $fixture['campaign_a'],
        50,
        $firstPage['next_page_after_result_id'],
    );
    monitorAssert($secondReconciliationPage !== null, 'Reconciliation second page did not resolve the active batch.');
    $secondPage = $secondReconciliationPage['reconciliation'];
    monitorAssert(
        count($secondPage['result_ids']) === 5
        && $secondPage['has_more'] === false
        && $secondPage['next_page_after_result_id'] === $secondPage['result_ids'][4]
        && $secondPage['result_ids'][0] > $firstPage['next_page_after_result_id'],
        'Reconciliation did not drain the remaining active-batch IDs.',
    );
    $allKnownIds = array_values(array_unique([...$firstPage['result_ids'], ...$secondPage['result_ids']]));
    monitorAssert(
        count($allKnownIds) === $secondReconciliationPage['summary']['completed_count'],
        'Reconciliation IDs do not match the authoritative completed count.',
    );
    $simulatedKnownIds = array_values(array_filter(
        $allKnownIds,
        static fn(int $resultId): bool => $resultId !== $firstPage['result_ids'][0],
    ));
    monitorAssert(
        count($simulatedKnownIds) < $secondReconciliationPage['summary']['completed_count'],
        'Late-result reconciliation fixture did not create a completeness mismatch.',
    );
    $restartPage = $repository->readActiveBatch($fixture['campaign_a'], 50, 0);
    monitorAssert($restartPage !== null, 'Reconciliation restart did not resolve the active batch.');
    $recoveredKnownIds = array_values(array_unique([...$simulatedKnownIds, ...$restartPage['reconciliation']['result_ids']]));
    monitorAssert(
        in_array($firstPage['result_ids'][0], $recoveredKnownIds, true),
        'Restarting reconciliation from zero did not recover the simulated late older ID.',
    );

    $invalidReconciliationPositionRejected = false;
    try {
        $repository->readActiveBatch($fixture['campaign_a'], 1, -1);
    } catch (InvalidArgumentException) {
        $invalidReconciliationPositionRejected = true;
    }
    monitorAssert($invalidReconciliationPositionRejected, 'Negative reconciliation page position was accepted.');

    echo "Monitor read repository tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . MONITOR_READ_TEST_DATABASE);
    }
}
