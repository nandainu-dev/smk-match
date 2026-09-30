<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\SmartLinkRepository;

const PUBLIC_MONITOR_HTTP_TEST_DATABASE = 'smk_match_g12_r4_test';

function publicMonitorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function publicMonitorMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $sql = file_get_contents($path);
        publicMonitorAssert($sql !== false, 'Migration could not be read.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @return array{school_id:int,campaign_id:int,batch_id:int,version_id:int,program_ids:array<string,int>} */
function publicMonitorFixture(PDO $connection): array
{
    $connection->exec("INSERT INTO schools (public_uuid,name,created_at,updated_at) VALUES ('a2200000-0000-4000-8000-000000000001','Public Monitor School',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $schoolId = (int) $connection->lastInsertId();
    $programIds = [];
    foreach (['ALPHA', 'BETA', 'GAMMA', 'DELTA'] as $code) {
        $statement = $connection->prepare('INSERT INTO programs (school_id,name,short_name,created_at,updated_at) VALUES (:school_id,:name,:code,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $statement->execute(['school_id' => $schoolId, 'name' => $code . ' Snapshot', 'code' => $code]);
        $programIds[$code] = (int) $connection->lastInsertId();
    }
    $connection->exec("INSERT INTO quizzes (school_id,name,created_at,updated_at) VALUES ({$schoolId},'Public Monitor Quiz',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $quizId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO quiz_versions (quiz_id,version_number,status,name,published_at,created_at) VALUES ({$quizId},1,'published','Public Monitor Version',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $versionId = (int) $connection->lastInsertId();
    foreach ($programIds as $code => $programId) {
        $connection->exec("INSERT INTO quiz_version_programs (quiz_version_id,program_id,created_at) VALUES ({$versionId},{$programId},UTC_TIMESTAMP())");
        $membershipId = (int) $connection->lastInsertId();
        $statement = $connection->prepare(
            'INSERT INTO quiz_version_program_presentations (
                quiz_version_program_id,program_code_snapshot,program_name_snapshot,
                personality_title_snapshot,mascot_path_snapshot,primary_color_snapshot,
                accent_color_snapshot,tagline_snapshot,description_snapshot,superpower_snapshot,
                skills_snapshot,careers_snapshot,snapshot_provenance,created_at,updated_at
            ) VALUES (
                :membership,:code,:name,:title,:mascot,:primary,:accent,:tagline,:description,:superpower,
                :skills,:careers,:provenance,UTC_TIMESTAMP(),UTC_TIMESTAMP()
            )'
        );
        $statement->execute([
            'membership' => $membershipId,
            'code' => $code,
            'name' => $code . ' Snapshot',
            'title' => $code . ' title',
            'mascot' => '/snapshot/' . strtolower($code) . '.png',
            'primary' => '#123456',
            'accent' => '#abcdef',
            'tagline' => $code . ' tagline',
            'description' => $code . ' description',
            'superpower' => $code . ' superpower',
            'skills' => '["skill"]',
            'careers' => '["career"]',
            'provenance' => 'version_snapshot',
        ]);
    }
    $connection->exec("INSERT INTO campaigns (school_id,quiz_id,quiz_version_id,name,status,created_at,updated_at) VALUES ({$schoolId},{$quizId},{$versionId},'Public Monitor Campaign','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $campaignId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO campaign_batches (campaign_id,batch_number,quiz_version_id,label,status,active_marker,started_at,created_at,updated_at) VALUES ({$campaignId},1,{$versionId},'Main batch','active',1,'2026-10-01 08:00:00',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $batchId = (int) $connection->lastInsertId();

    return ['school_id' => $schoolId, 'campaign_id' => $campaignId, 'batch_id' => $batchId, 'version_id' => $versionId, 'program_ids' => $programIds];
}

/** @param array{school_id:int,campaign_id:int,batch_id:int,version_id:int,program_ids:array<string,int>} $fixture */
function publicMonitorResult(PDO $connection, array $fixture, int $number): void
{
    $connection->exec("INSERT INTO participants (school_id,public_uuid,full_name,origin_school,class_name,phone,marketing_consent,marketing_consent_at,created_at,updated_at) VALUES ({$fixture['school_id']},'a2300000-0000-4000-8000-" . sprintf('%012d', $number) . "','Monitor Student {$number}','Private Origin','Class Secret','0812345',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $participantId = (int) $connection->lastInsertId();
    $submittedAt = sprintf('2026-10-01 08:%02d:00', $number);
    $attempt = $connection->prepare('INSERT INTO attempts (participant_id,campaign_id,campaign_batch_id,quiz_version_id,visitor_uuid,attempt_uuid,source,status,submitted_at,created_at) VALUES (:participant,:campaign,:batch,:version,:visitor,:attempt,:source,:status,:submitted,UTC_TIMESTAMP())');
    $attempt->execute([
        'participant' => $participantId,
        'campaign' => $fixture['campaign_id'],
        'batch' => $fixture['batch_id'],
        'version' => $fixture['version_id'],
        'visitor' => sprintf('a2400000-0000-4000-8000-%012d', $number),
        'attempt' => sprintf('a2500000-0000-4000-8000-%012d', $number),
        'source' => 'public-monitor-test',
        'status' => 'completed',
        'submitted' => $submittedAt,
    ]);
    $attemptId = (int) $connection->lastInsertId();
    $dominant = $fixture['program_ids']['ALPHA'];
    $result = $connection->prepare('INSERT INTO results (attempt_id,total_raw_score,dominant_program_id,is_tie,created_at) VALUES (:attempt,100,:dominant,0,UTC_TIMESTAMP())');
    $result->execute(['attempt' => $attemptId, 'dominant' => $dominant]);
    $resultId = (int) $connection->lastInsertId();
    $score = $connection->prepare('INSERT INTO result_scores (result_id,program_id,raw_score,normalized_percentage,display_order,created_at) VALUES (:result,:program,:raw,:percentage,:display,UTC_TIMESTAMP())');
    $display = 1;
    foreach ($fixture['program_ids'] as $programId) {
        $percentage = $programId === $dominant ? 70.0 : 10.0;
        $score->execute(['result' => $resultId, 'program' => $programId, 'raw' => $percentage, 'percentage' => $percentage, 'display' => $display]);
        $display++;
    }
}

/** @param array<string,mixed> $value */
function publicMonitorHasForbiddenKey(array $value): bool
{
    $forbidden = ['phone', 'marketing_consent', 'marketing_consent_at', 'public_uuid', 'visitor_uuid', 'attempt_uuid', 'origin_school', 'class_name', 'raw_score', 'total_raw_score', 'campaign_id', 'quiz_version_id'];
    foreach ($value as $key => $item) {
        if (is_string($key) && in_array($key, $forbidden, true)) {
            return true;
        }
        if (is_array($item) && publicMonitorHasForbiddenKey($item)) {
            return true;
        }
    }

    return false;
}

/** @return array<string,int> */
function publicMonitorCounts(PDO $connection): array
{
    $counts = [];
    foreach (['smart_links', 'attempts', 'results', 'result_scores'] as $table) {
        $counts[$table] = (int) $connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    return $counts;
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
publicMonitorAssert($host === '127.0.0.1' && $port === '3306' && $databaseName === PUBLIC_MONITOR_HTTP_TEST_DATABASE, 'Unsafe test database configuration.');
publicMonitorAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $admin->exec('DROP DATABASE IF EXISTS ' . PUBLIC_MONITOR_HTTP_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . PUBLIC_MONITOR_HTTP_TEST_DATABASE . ' CHARACTER SET utf8mb4');
    $database = new Database(Config::load(SMK_MATCH_ROOT));
    $connection = $database->connection();
    publicMonitorMigrations($connection);
    $fixture = publicMonitorFixture($connection);
    for ($number = 1; $number <= 21; $number++) {
        publicMonitorResult($connection, $fixture, $number);
    }
    $links = new SmartLinkRepository($database);
    $links->create($fixture['school_id'], $fixture['campaign_id'], 'Monitor', 'monitor-http', null, true);
    $links->create($fixture['school_id'], $fixture['campaign_id'], 'Inactive', 'monitor-inactive', null, false);
    $connection->exec("INSERT INTO campaigns (school_id,quiz_id,quiz_version_id,name,status,created_at,updated_at) VALUES ({$fixture['school_id']},1,{$fixture['version_id']},'No Batch','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $noBatchCampaign = (int) $connection->lastInsertId();
    $links->create($fixture['school_id'], $noBatchCampaign, 'No Batch', 'monitor-no-batch', null, true);

    $routes = require SMK_MATCH_ROOT . '/routes/web.php';
    $router = $routes(Config::load(SMK_MATCH_ROOT));
    $before = publicMonitorCounts($connection);
    $response = $router->dispatch(new Request('GET', '/api/public/monitor/monitor-http'));
    $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    publicMonitorAssert($response->status === 200 && $payload['ok'] === true && count($payload['monitor']['recent_activity']) === 20, 'Default monitor response is invalid.');
    publicMonitorAssert(($response->headers['Content-Type'] ?? null) === 'application/json; charset=utf-8' && ($response->headers['Cache-Control'] ?? null) === 'no-store, max-age=0', 'Monitor response headers are invalid.');
    publicMonitorAssert(!publicMonitorHasForbiddenKey($payload) && !array_key_exists('after_result_id', $payload['monitor']), 'Monitor response exposes private data or a cursor.');
    publicMonitorAssert($before === publicMonitorCounts($connection), 'Monitor GET mutated persisted data.');

    $reconciliation = $router->dispatch(new Request('GET', '/api/public/monitor/monitor-http', [], '', [], false, ['recentLimit' => '20', 'reconciliationAfterResultId' => '0']));
    $reconciliationPayload = json_decode($reconciliation->body, true, 512, JSON_THROW_ON_ERROR);
    publicMonitorAssert(
        $reconciliation->status === 200
        && count($reconciliationPayload['monitor']['reconciliation']['result_ids']) === 20
        && $reconciliationPayload['monitor']['reconciliation']['has_more'] === true
        && $reconciliationPayload['monitor']['reconciliation']['next_page_after_result_id'] === $reconciliationPayload['monitor']['reconciliation']['result_ids'][19]
        && !publicMonitorHasForbiddenKey($reconciliationPayload),
        'Bounded public reconciliation response is invalid.',
    );
    $reconciliationNext = $router->dispatch(new Request('GET', '/api/public/monitor/monitor-http', [], '', [], false, ['recentLimit' => '20', 'reconciliationAfterResultId' => (string) $reconciliationPayload['monitor']['reconciliation']['next_page_after_result_id']]));
    $reconciliationNextPayload = json_decode($reconciliationNext->body, true, 512, JSON_THROW_ON_ERROR);
    publicMonitorAssert(
        $reconciliationNext->status === 200
        && count($reconciliationNextPayload['monitor']['reconciliation']['result_ids']) === 1
        && $reconciliationNextPayload['monitor']['reconciliation']['has_more'] === false,
        'Public reconciliation pagination did not drain.',
    );

    $limited = $router->dispatch(new Request('GET', '/api/public/monitor/monitor-http', [], '', [], false, ['recentLimit' => '1']));
    $limitedPayload = json_decode($limited->body, true, 512, JSON_THROW_ON_ERROR);
    publicMonitorAssert($limited->status === 200 && count($limitedPayload['monitor']['recent_activity']) === 1, 'Valid recentLimit was not forwarded.');
    $cursorIgnored = $router->dispatch(new Request('GET', '/api/public/monitor/monitor-http', [], '', [], false, ['after_result_id' => '1']));
    publicMonitorAssert($cursorIgnored->status === 200, 'Unsupported cursor query changed monitor behavior.');
    foreach (['', '0', '-1', '1.5', 'abc', '01'] as $invalidLimit) {
        $invalid = $router->dispatch(new Request('GET', '/api/public/monitor/monitor-http', [], '', [], false, ['recentLimit' => $invalidLimit]));
        publicMonitorAssert($invalid->status === 422 && $invalid->body === '{"ok":false,"error":"invalid_recent_limit"}', 'Invalid recentLimit was accepted: ' . $invalidLimit);
    }
    foreach ([['1', '2'], [null]] as $invalidValues) {
        $invalid = $router->dispatch(new Request('GET', '/api/public/monitor/monitor-http', [], '', [], false, ['recentLimit' => $invalidValues]));
        publicMonitorAssert($invalid->status === 422, 'Repeated or array recentLimit was accepted.');
    }
    foreach (['', '-1', '01', '1.5', 'abc'] as $invalidPosition) {
        $invalid = $router->dispatch(new Request('GET', '/api/public/monitor/monitor-http', [], '', [], false, ['reconciliationAfterResultId' => $invalidPosition]));
        publicMonitorAssert($invalid->status === 422 && $invalid->body === '{"ok":false,"error":"invalid_reconciliation_page"}', 'Invalid reconciliation page position was accepted: ' . $invalidPosition);
    }
    $repeatedPosition = $router->dispatch(new Request('GET', '/api/public/monitor/monitor-http', [], '', [], false, ['reconciliationAfterResultId' => ['0', '1']]));
    publicMonitorAssert($repeatedPosition->status === 422, 'Repeated reconciliation page position was accepted.');
    foreach (['unknown-monitor', 'monitor-inactive', 'monitor-no-batch', 'admin', 'INVALID LINK'] as $alias) {
        $notFound = $router->dispatch(new Request('GET', '/api/public/monitor/' . $alias));
        publicMonitorAssert($notFound->status === 404 && $notFound->body === '{"ok":false,"error":"not_found"}', 'Unsafe monitor availability response: ' . $alias);
    }
    $connection->exec('DELETE FROM quiz_version_program_presentations WHERE program_code_snapshot = \'DELTA\'');
    $internal = $router->dispatch(new Request('GET', '/api/public/monitor/monitor-http'));
    publicMonitorAssert($internal->status === 500 && $internal->body === '{"ok":false,"error":"internal_error"}', 'Unexpected monitor failure was not safely represented.');

    echo "Public monitor HTTP tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . PUBLIC_MONITOR_HTTP_TEST_DATABASE);
    }
    putenv('DB_PASSWORD');
}
