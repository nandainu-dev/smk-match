<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\SmartLinkRepository;

const PUBLIC_MONITOR_PAGE_TEST_DATABASE = 'smk_match_g12_r5_test';

function publicMonitorPageAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function publicMonitorPageMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $sql = file_get_contents($path);
        publicMonitorPageAssert($sql !== false, 'Migration could not be read.');

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @return array{school_id:int,campaign_id:int} */
function publicMonitorPageFixture(PDO $connection): array
{
    $connection->exec("INSERT INTO schools (public_uuid,name,created_at,updated_at) VALUES ('a3200000-0000-4000-8000-000000000001','Monitor Page School',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $schoolId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO quizzes (school_id,name,created_at,updated_at) VALUES ({$schoolId},'Monitor Page Quiz',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $quizId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO quiz_versions (quiz_id,version_number,status,name,published_at,created_at) VALUES ({$quizId},1,'published','Monitor Page Version',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $versionId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO campaigns (school_id,quiz_id,quiz_version_id,name,status,created_at,updated_at) VALUES ({$schoolId},{$quizId},{$versionId},'Monitor Page Campaign','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $campaignId = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO campaign_batches (campaign_id,batch_number,quiz_version_id,label,status,active_marker,started_at,created_at,updated_at) VALUES ({$campaignId},1,{$versionId},'Monitor page batch','active',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");

    return ['school_id' => $schoolId, 'campaign_id' => $campaignId];
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
publicMonitorPageAssert($host === '127.0.0.1' && $port === '3306' && $databaseName === PUBLIC_MONITOR_PAGE_TEST_DATABASE, 'Unsafe test database configuration.');
publicMonitorPageAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $admin->exec('DROP DATABASE IF EXISTS ' . PUBLIC_MONITOR_PAGE_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . PUBLIC_MONITOR_PAGE_TEST_DATABASE . ' CHARACTER SET utf8mb4');
    $database = new Database(Config::load(SMK_MATCH_ROOT));
    $connection = $database->connection();
    publicMonitorPageMigrations($connection);
    $fixture = publicMonitorPageFixture($connection);
    $links = new SmartLinkRepository($database);
    $links->create($fixture['school_id'], $fixture['campaign_id'], 'Monitor page', 'monitor-page', null, true);

    $routes = require SMK_MATCH_ROOT . '/routes/web.php';
    $router = $routes(Config::load(SMK_MATCH_ROOT));
    $response = $router->dispatch(new Request('GET', '/monitor/monitor-page'));

    publicMonitorPageAssert($response->status === 200, 'Monitor shell route did not render.');
    publicMonitorPageAssert(($response->headers['Content-Type'] ?? null) === 'text/html; charset=utf-8', 'Monitor shell content type is invalid.');
    preg_match_all('/data-monitor-slide="([^"]+)"/', $response->body, $matches);
    publicMonitorPageAssert($matches[1] === ['overview', 'dkv', 'mplb', 'pm'], 'Monitor shell slide identities are invalid.');
    publicMonitorPageAssert(substr_count($response->body, 'data-monitor-slide=') === 4, 'Monitor shell must contain exactly four slides.');
    publicMonitorPageAssert(str_contains($response->body, 'class="monitor-sidebar"') && str_contains($response->body, 'monitor-qr-placeholder') && str_contains($response->body, 'monitor-activity-list'), 'Monitor sidebar placeholders are missing.');
    publicMonitorPageAssert(str_contains($response->body, '/assets/fonts/FredokaOne-Regular.ttf') === false, 'Font must be referenced by local stylesheet only.');
    publicMonitorPageAssert(str_contains($response->body, '/assets/css/monitor.css') && str_contains($response->body, '/assets/js/monitor-shell.js'), 'Monitor shell local assets are missing.');
    publicMonitorPageAssert(str_contains($response->body, 'data-monitor-alias="monitor-page"'), 'Monitor alias was not safely rendered.');
    publicMonitorPageAssert(!str_contains($response->body, 'campaign_id') && !str_contains($response->body, 'attempt_uuid') && !str_contains($response->body, 'phone'), 'Monitor shell bootstraps sensitive data.');
    publicMonitorPageAssert($router->dispatch(new Request('GET', '/monitor/unknown-page'))->status === 404, 'Unknown monitor alias must be hidden.');

    $stylesheet = file_get_contents(SMK_MATCH_ROOT . '/public/assets/css/monitor.css');
    $script = file_get_contents(SMK_MATCH_ROOT . '/public/assets/js/monitor-shell.js');
    publicMonitorPageAssert(is_string($stylesheet) && str_contains($stylesheet, '@media (max-width: 1100px)') && str_contains($stylesheet, '@media (max-width: 700px)'), 'Responsive shell hooks are missing.');
    publicMonitorPageAssert(is_string($script), 'Monitor shell script could not be read.');
    foreach (['fetch(', 'XMLHttpRequest', 'setInterval', 'setTimeout', 'WebSocket', 'EventSource', 'after_result_id'] as $forbidden) {
        publicMonitorPageAssert(!str_contains($script, $forbidden), 'Monitor shell contains forbidden live-data behavior: ' . $forbidden);
    }

    echo "Public monitor page tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . PUBLIC_MONITOR_PAGE_TEST_DATABASE);
    }
    putenv('DB_PASSWORD');
}
