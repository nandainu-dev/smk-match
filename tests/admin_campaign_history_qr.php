<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\AdminCampaignController;
use App\Core\AdminCampaignRepository;
use App\Core\AdminCampaignService;
use App\Core\AdminHistoryReadRepository;
use App\Core\AdminAccessService;
use App\Core\AdminRepository;
use App\Core\AdminSession;
use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\CampaignService;
use App\Core\Config;
use App\Core\Database;
use App\Core\QuizDefinition;
use App\Core\QuizVersionProgramPresentationRepository;
use App\Core\QuizVersionRepository;
use App\Core\QuizVersionService;
use App\Core\Request;
use App\Core\SmartLinkRepository;
use App\Core\SmartLinkService;

const ADMIN_CAMPAIGN_HISTORY_TEST_DATABASE = 'smk_match_g13_r4_test';

function adminCampaignAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function adminCampaignMigrationStatements(string $path): array
{
    $contents = file_get_contents($path);
    adminCampaignAssert($contents !== false, 'Migration could not be read.');

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

function adminCampaignApplyMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migration) {
        foreach (adminCampaignMigrationStatements($migration) as $statement) {
            $connection->exec($statement);
        }
    }
}

function adminCampaignResetSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }

    session_id('');
    $_SESSION = [];
}

/** @param array<string, string> $headers @param array<string, mixed> $query @param array<string, mixed> $form */
function adminCampaignRequest(string $method, string $path, array $query = [], array $form = [], array $headers = []): Request
{
    return new Request($method, $path, $headers, '', [], false, $query, $form);
}

function adminCampaignDefinition(): QuizDefinition
{
    return new QuizDefinition('Campaign quiz', 1, ['ALPHA' => true, 'GAMMA' => true], [[
        'id' => 'question-1',
        'text' => 'Question',
        'order' => 10,
        'options' => [
            ['id' => 'option-1', 'text' => 'Option A', 'order' => 10, 'weights' => [['program' => 'ALPHA', 'weight' => 1], ['program' => 'GAMMA', 'weight' => 1]]],
            ['id' => 'option-2', 'text' => 'Option B', 'order' => 20, 'weights' => [['program' => 'ALPHA', 'weight' => 2], ['program' => 'GAMMA', 'weight' => 2]]],
        ],
    ]]);
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
adminCampaignAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === ADMIN_CAMPAIGN_HISTORY_TEST_DATABASE,
    'Unsafe test database configuration.',
);
adminCampaignAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $admin->exec('DROP DATABASE IF EXISTS ' . ADMIN_CAMPAIGN_HISTORY_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . ADMIN_CAMPAIGN_HISTORY_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $connection = $database->connection();
    adminCampaignApplyMigrations($connection);

    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('14141414-1414-4141-8141-141414141414', 'Campaign School A', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolA = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('24242424-2424-4242-8242-242424242424', 'Campaign School B', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolB = (int) $connection->lastInsertId();

    $program = $connection->prepare('INSERT INTO programs (school_id, name, short_name, created_at, updated_at) VALUES (:school_id, :name, :code, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $program->execute(['school_id' => $schoolA, 'name' => 'Alpha Snapshot', 'code' => 'ALPHA']);
    $programA = (int) $connection->lastInsertId();
    $program->execute(['school_id' => $schoolA, 'name' => 'Gamma Snapshot', 'code' => 'GAMMA']);
    $programGamma = (int) $connection->lastInsertId();
    $program->execute(['school_id' => $schoolB, 'name' => 'Beta Alpha Snapshot', 'code' => 'ALPHA']);
    $program->execute(['school_id' => $schoolB, 'name' => 'Beta Gamma Snapshot', 'code' => 'GAMMA']);

    $adminInsert = $connection->prepare('INSERT INTO admins (school_id, name, email, password_hash, is_active, created_at, updated_at) VALUES (:school_id, :name, :email, :password_hash, :active, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $hash = password_hash('campaign-password', PASSWORD_DEFAULT);
    adminCampaignAssert(is_string($hash), 'Admin password fixture could not be generated.');
    $adminInsert->execute(['school_id' => $schoolA, 'name' => 'Campaign Admin', 'email' => 'campaign@example.test', 'password_hash' => $hash, 'active' => 1]);
    $adminA = (int) $connection->lastInsertId();
    $adminInsert->execute(['school_id' => $schoolB, 'name' => 'Inactive Admin', 'email' => 'inactive@example.test', 'password_hash' => $hash, 'active' => 0]);

    $quizInsert = $connection->prepare('INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES (:school_id, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $quizInsert->execute(['school_id' => $schoolA, 'name' => 'Campaign Quiz A']);
    $quizA = (int) $connection->lastInsertId();
    $quizInsert->execute(['school_id' => $schoolB, 'name' => 'Campaign Quiz B']);
    $quizB = (int) $connection->lastInsertId();

    $versions = new QuizVersionRepository($database, new QuizVersionProgramPresentationRepository($database));
    $versionService = new QuizVersionService($versions);
    $versionA = $versionService->getOrCreateDraft($quizA, adminCampaignDefinition());
    $versionService->publish($quizA, $versionA->id);
    $versionA2 = $versionService->getOrCreateDraft($quizA, adminCampaignDefinition());
    $versionService->publish($quizA, $versionA2->id);
    $draftVersionA = $versionService->getOrCreateDraft($quizA, adminCampaignDefinition());
    $versionB = $versionService->getOrCreateDraft($quizB, adminCampaignDefinition());
    $versionService->publish($quizB, $versionB->id);

    $campaignInsert = $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :quiz_version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $campaignInsert->execute(['school_id' => $schoolA, 'quiz_id' => $quizA, 'quiz_version_id' => $versionA->id, 'name' => 'Campaign A', 'status' => 'draft']);
    $campaignA = (int) $connection->lastInsertId();
    $campaignInsert->execute(['school_id' => $schoolB, 'quiz_id' => $quizB, 'quiz_version_id' => $versionB->id, 'name' => 'Campaign B', 'status' => 'draft']);
    $campaignB = (int) $connection->lastInsertId();

    $campaignService = new CampaignService($database, new CampaignRepository($database), new CampaignBatchRepository($database), $versions);
    $batchA = $campaignService->activateCampaign($campaignA, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));
    $batchB = $campaignService->activateCampaign($campaignB, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));
    $smartLinks = new SmartLinkService(new SmartLinkRepository($database), new CampaignRepository($database), new CampaignBatchRepository($database));
    $link = $smartLinks->create($schoolA, $campaignA, 'Campaign A QR', 'campaign-a');
    $smartLinks->create($schoolB, $campaignB, 'Campaign B QR', 'campaign-b');

    $connection->exec("INSERT INTO participants (school_id, public_uuid, full_name, origin_school, class_name, phone, marketing_consent, created_at, updated_at) VALUES ({$schoolA}, '34343434-3434-4343-8343-343434343434', 'Privacy Participant', 'Private Origin', 'XII', '08123456789', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $participantA = (int) $connection->lastInsertId();
    $question = $connection->query('SELECT id FROM questions WHERE quiz_version_id = ' . $versionA->id . ' ORDER BY id ASC LIMIT 1')->fetchColumn();
    $option = $connection->query('SELECT id FROM question_options WHERE question_id = ' . (int) $question . ' ORDER BY id ASC LIMIT 1')->fetchColumn();
    adminCampaignAssert(is_numeric($question) && is_numeric($option), 'Quiz fixture did not persist question data.');
    $connection->prepare('INSERT INTO attempts (participant_id, campaign_id, campaign_batch_id, quiz_version_id, visitor_uuid, attempt_uuid, source, status, submitted_at, created_at) VALUES (:participant_id, :campaign_id, :batch_id, :version_id, :visitor_uuid, :attempt_uuid, :source, :status, :submitted_at, :created_at)')->execute([
        'participant_id' => $participantA,
        'campaign_id' => $campaignA,
        'batch_id' => $batchA->id,
        'version_id' => $versionA->id,
        'visitor_uuid' => '45454545-4545-4545-8454-454545454545',
        'attempt_uuid' => '56565656-5656-4565-8565-565656565656',
        'source' => 'test',
        'status' => 'completed',
        'submitted_at' => '2026-01-01 17:15:00',
        'created_at' => '2026-01-01 17:00:00',
    ]);
    $attemptA = (int) $connection->lastInsertId();
    $connection->prepare('INSERT INTO responses (attempt_id, question_id, question_option_id, created_at) VALUES (:attempt_id, :question_id, :option_id, UTC_TIMESTAMP())')->execute(['attempt_id' => $attemptA, 'question_id' => (int) $question, 'option_id' => (int) $option]);
    $connection->prepare('INSERT INTO results (attempt_id, total_raw_score, dominant_program_id, is_tie, created_at) VALUES (:attempt_id, 2, NULL, 1, UTC_TIMESTAMP())')->execute(['attempt_id' => $attemptA]);
    $resultA = (int) $connection->lastInsertId();
    $scoreInsert = $connection->prepare('INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, display_order, created_at) VALUES (:result_id, :program_id, 1, 50, :display_order, UTC_TIMESTAMP())');
    $scoreInsert->execute(['result_id' => $resultA, 'program_id' => $programA, 'display_order' => 1]);
    $scoreInsert->execute(['result_id' => $resultA, 'program_id' => $programGamma, 'display_order' => 2]);
    $tieInsert = $connection->prepare('INSERT INTO result_tied_programs (result_id, program_id) VALUES (:result_id, :program_id)');
    $tieInsert->execute(['result_id' => $resultA, 'program_id' => $programA]);
    $tieInsert->execute(['result_id' => $resultA, 'program_id' => $programGamma]);

    $historyRepository = new AdminHistoryReadRepository($database);
    $adminRepository = new AdminCampaignRepository($database);
    $adminService = new AdminCampaignService($config, $adminRepository, $historyRepository, $campaignService);
    $session = new AdminSession();
    $controller = new AdminCampaignController($config, $session, $adminService);

    adminCampaignAssert(
        (new AdminAccessService(new AdminRepository($database)))->authenticate('inactive@example.test', 'campaign-password') === null,
        'Inactive admin was accepted.',
    );

    adminCampaignResetSession();
    adminCampaignAssert($controller->index(adminCampaignRequest('GET', '/admin/campaigns'))->status === 302, 'Unauthenticated campaign page was accepted.');
    $session->start(false);
    $session->login(['admin_id' => $adminA, 'school_id' => $schoolA]);
    $csrf = $session->csrfToken();

    $dashboard = $controller->index(adminCampaignRequest('GET', '/admin/campaigns', ['campaign_id' => (string) $campaignA, 'school_id' => (string) $schoolB]));
    adminCampaignAssert($dashboard->status === 200 && str_contains($dashboard->body, 'Campaign A') && !str_contains($dashboard->body, 'Campaign B'), 'Campaign dashboard did not enforce school scope.');
    adminCampaignAssert(str_contains($dashboard->body, 'QR belum dibuat') && !str_contains($dashboard->body, 'data-admin-qr-target='), 'Un-generated Smart QR target was not kept empty.');
    adminCampaignAssert(str_contains($dashboard->body, 'Versi 1') && str_contains($dashboard->body, 'Versi 2') && str_contains($dashboard->body, 'Sedang aktif') && !str_contains($dashboard->body, 'Versi 3'), 'Campaign version selector did not expose only published versions and the current version.');
    adminCampaignAssert($controller->activateQuizVersion(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignA . '/quiz-versions/' . $versionA2->id . '/activate'), (string) $campaignA, (string) $versionA2->id)->status === 403, 'Version activation without CSRF was accepted.');
    adminCampaignAssert($controller->activateQuizVersion(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignA . '/quiz-versions/' . $versionA2->id . '/activate', [], ['csrf_token' => $csrf, 'confirmation' => 'NO']), (string) $campaignA, (string) $versionA2->id)->status === 422, 'Version activation without explicit confirmation was accepted.');
    adminCampaignAssert($controller->activateQuizVersion(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignA . '/quiz-versions/' . $draftVersionA->id . '/activate', [], ['csrf_token' => $csrf, 'confirmation' => 'AKTIFKAN']), (string) $campaignA, (string) $draftVersionA->id)->status === 404, 'Draft version activation was accepted.');
    adminCampaignAssert($controller->activateQuizVersion(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignB . '/quiz-versions/' . $versionB->id . '/activate', [], ['csrf_token' => $csrf, 'confirmation' => 'AKTIFKAN']), (string) $campaignB, (string) $versionB->id)->status === 404, 'Cross-school version activation was accepted.');
    $lanSuggestion = $controller->index(adminCampaignRequest('GET', '/admin/campaigns', ['campaign_id' => (string) $campaignA], [], ['Host' => '10.104.37.122:8081']));
    adminCampaignAssert(str_contains($lanSuggestion->body, 'value="http://10.104.37.122:8081"'), 'Private LAN host was not offered as an editable QR base URL suggestion.');
    adminCampaignAssert(str_contains($dashboard->body, 'http://127.0.0.1:8080/monitor/campaign-a') && str_contains($dashboard->body, 'Buka Monitor'), 'Campaign dashboard does not expose the matching read-only Monitor URL.');
    $routes = require SMK_MATCH_ROOT . '/routes/web.php';
    $router = $routes($config);
    $monitorBeforeGeneration = $router->dispatch(new Request('GET', '/monitor/campaign-a'));
    adminCampaignAssert($monitorBeforeGeneration->status === 200 && str_contains($monitorBeforeGeneration->body, 'data-monitor-qr-target=""') && str_contains($monitorBeforeGeneration->body, 'QR belum dibuat oleh admin.'), 'Monitor silently generated a QR target.');
    adminCampaignAssert($controller->generateQr(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignA . '/smart-links/campaign-a/qr', [], ['base_url' => 'http://10.104.37.122:8081']), (string) $campaignA, 'campaign-a')->status === 403, 'QR generation without CSRF was accepted.');
    adminCampaignAssert($controller->generateQr(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignB . '/smart-links/campaign-b/qr', [], ['csrf_token' => $csrf, 'base_url' => 'http://10.104.37.122:8081']), (string) $campaignB, 'campaign-b')->status === 404, 'Cross-school QR generation was accepted.');
    adminCampaignAssert($controller->generateQr(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignA . '/smart-links/campaign-a/qr', [], ['csrf_token' => $csrf, 'base_url' => 'https://example.test/admin']), (string) $campaignA, 'campaign-a')->status === 422, 'QR generation accepted a non-origin base URL.');

    $baseA = 'http://10.104.37.122:8081';
    $targetA = $baseA . '/go/campaign-a';
    adminCampaignAssert($controller->generateQr(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignA . '/smart-links/campaign-a/qr', [], ['csrf_token' => $csrf, 'base_url' => $baseA]), (string) $campaignA, 'campaign-a')->status === 302, 'Authorized QR generation failed.');
    adminCampaignAssert($adminRepository->findSmartLinkForCampaign($schoolA, $campaignA, 'campaign-a')?->qrTargetUrl === $targetA, 'Generated QR target was not persisted.');
    $dashboardA = $controller->index(adminCampaignRequest('GET', '/admin/campaigns', ['campaign_id' => (string) $campaignA]));
    $monitorA = $router->dispatch(new Request('GET', '/monitor/campaign-a'));
    adminCampaignAssert(str_contains($dashboardA->body, 'data-admin-qr-target="' . $targetA . '"') && str_contains($monitorA->body, 'data-monitor-qr-target="' . $targetA . '"'), 'Admin and Monitor did not use the same persisted QR target.');

    putenv('SMK_MATCH_BASE_URL=http://192.168.1.50:8081');
    $changedConfig = Config::load(SMK_MATCH_ROOT);
    $changedDashboard = (new AdminCampaignService($changedConfig, $adminRepository, $historyRepository, $campaignService))->campaignDashboard($schoolA, $campaignA);
    $changedRouter = $routes($changedConfig);
    $changedMonitor = $changedRouter->dispatch(new Request('GET', '/monitor/campaign-a'));
    putenv('SMK_MATCH_BASE_URL');
    adminCampaignAssert($changedDashboard['smart_links'][0]['qr_target_url'] === $targetA && str_contains($changedMonitor->body, 'data-monitor-qr-target="' . $targetA . '"'), 'Base URL change silently rewrote the persisted QR target.');

    $baseB = 'http://192.168.1.50:8081';
    $targetB = $baseB . '/go/campaign-a';
    adminCampaignAssert($controller->generateQr(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignA . '/smart-links/campaign-a/qr', [], ['csrf_token' => $csrf, 'base_url' => $baseB]), (string) $campaignA, 'campaign-a')->status === 302, 'QR regeneration failed.');
    $dashboardB = $controller->index(adminCampaignRequest('GET', '/admin/campaigns', ['campaign_id' => (string) $campaignA]));
    $monitorB = $router->dispatch(new Request('GET', '/monitor/campaign-a'));
    adminCampaignAssert($targetA !== $targetB && str_contains($dashboardB->body, 'data-admin-qr-target="' . $targetB . '"') && str_contains($monitorB->body, 'data-monitor-qr-target="' . $targetB . '"'), 'QR regeneration did not update Admin and Monitor together.');
    adminCampaignAssert($controller->index(adminCampaignRequest('GET', '/admin/campaigns', ['campaign_id' => (string) $campaignB]))->status === 404, 'Cross-school campaign was accepted.');
    adminCampaignAssert($controller->reset(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignA . '/batches/reset'), (string) $campaignA)->status === 403, 'Reset without CSRF was accepted.');
    adminCampaignAssert($controller->reset(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignA . '/batches/reset', [], ['csrf_token' => $csrf, 'confirmation' => 'NO']), (string) $campaignA)->status === 422, 'Reset without explicit confirmation was accepted.');

    $reset = $controller->reset(adminCampaignRequest('POST', '/admin/campaigns/' . $campaignA . '/batches/reset', [], ['csrf_token' => $csrf, 'confirmation' => 'RESET']), (string) $campaignA);
    adminCampaignAssert($reset->status === 302, 'Authorized batch reset failed.');
    $batches = $adminRepository->listBatchesForCampaign($schoolA, $campaignA);
    adminCampaignAssert(count($batches) === 2 && $batches[0]->batchNumber === 1 && $batches[0]->status === 'closed' && $batches[1]->batchNumber === 2 && $batches[1]->status === 'active', 'Batch reset did not preserve history or allocate MAX + 1.');
    adminCampaignAssert((int) $connection->query('SELECT COUNT(*) FROM participants WHERE id = ' . $participantA)->fetchColumn() === 1, 'Reset deleted participant history.');
    adminCampaignAssert((int) $connection->query('SELECT COUNT(*) FROM attempts WHERE id = ' . $attemptA)->fetchColumn() === 1, 'Reset deleted attempt history.');
    adminCampaignAssert((int) $connection->query('SELECT COUNT(*) FROM responses WHERE attempt_id = ' . $attemptA)->fetchColumn() === 1, 'Reset deleted response history.');
    adminCampaignAssert((int) $connection->query('SELECT COUNT(*) FROM results WHERE id = ' . $resultA)->fetchColumn() === 1, 'Reset deleted result history.');
    adminCampaignAssert((int) $connection->query('SELECT COUNT(*) FROM result_scores WHERE result_id = ' . $resultA)->fetchColumn() === 2, 'Reset deleted score history.');
    adminCampaignAssert((int) $connection->query('SELECT COUNT(*) FROM result_tied_programs WHERE result_id = ' . $resultA)->fetchColumn() === 2, 'Reset deleted tied-result history.');
    adminCampaignAssert((int) $connection->query('SELECT COUNT(*) FROM quiz_version_program_presentations WHERE quiz_version_program_id IN (SELECT id FROM quiz_version_programs WHERE quiz_version_id = ' . $versionA->id . ')')->fetchColumn() === 2, 'Reset deleted historical snapshots.');
    adminCampaignAssert($link->alias === 'campaign-a' && $smartLinks->resolve('campaign-a')->activeBatchId === $batches[1]->id, 'Reset changed Smart Link identity instead of resolving the new active batch.');

    $connection->prepare('UPDATE programs SET name = :name WHERE id = :id')->execute(['name' => 'Mutable Catalog Name', 'id' => $programA]);
    $report = $adminService->history($schoolA, $campaignA, $batchA->id, '2026-01-02', '2026-01-02');
    adminCampaignAssert(count($report['rows']) === 1 && array_column($report['rows'][0]['ranking'], 'display_order') === [1, 2], 'Historical report did not retain persisted display order.');
    adminCampaignAssert($report['rows'][0]['outcome']['kind'] === 'tie' && count($report['rows'][0]['outcome']['tied_programs']) === 2, 'Historical report did not retain tie state.');
    adminCampaignAssert($report['rows'][0]['ranking'][0]['program']['name'] === 'Alpha Snapshot', 'Historical report used mutable program data.');
    adminCampaignAssert($report['summary'][0]['code'] === 'ALPHA' && $report['summary'][0]['name'] === 'Alpha Snapshot', 'Program summary did not use version snapshots.');
    adminCampaignAssert($report['rows'][0]['origin_school'] === 'Private Origin' && $report['rows'][0]['class_name'] === 'XII' && $report['rows'][0]['phone'] === '08123456789', 'Authorized historical report does not provide the participant table fields.');
    $serializedReport = json_encode($report, JSON_THROW_ON_ERROR);
    foreach (['marketing_consent', 'visitor_uuid', 'attempt_uuid', 'responses'] as $forbidden) {
        adminCampaignAssert(!str_contains($serializedReport, $forbidden), 'Historical report leaked private data: ' . $forbidden);
    }
    adminCampaignAssert($adminService->history($schoolA, null, null, null, null)['rows'] !== [], 'All-history mode did not include owned attempts.');
    adminCampaignAssert($adminService->history($schoolA, $campaignA, $batchA->id, '2026-01-01', '2026-01-01')['rows'] === [], 'Jakarta local-date UTC conversion included the wrong day.');

    adminCampaignAssert($controller->history(adminCampaignRequest('GET', '/admin/history', ['campaign_id' => (string) $campaignA, 'batch_id' => (string) $batchB->id]))->status === 404, 'Cross-campaign batch selection was accepted.');
    adminCampaignAssert($controller->history(adminCampaignRequest('GET', '/admin/history', ['from' => '2026-02-30']))->status === 422, 'Malformed date was accepted.');
    adminCampaignAssert($controller->history(adminCampaignRequest('GET', '/admin/history', ['from' => '2026-01-03', 'to' => '2026-01-02']))->status === 422, 'Reversed date range was accepted.');
    $historyPage = $controller->history(adminCampaignRequest('GET', '/admin/history', ['campaign_id' => (string) $campaignA, 'batch_id' => (string) $batchA->id]));
    adminCampaignAssert($historyPage->status === 200 && str_contains($historyPage->body, 'formaction="/admin/history/export"') && str_contains($historyPage->body, '<table class="admin-history-table">') && str_contains($historyPage->body, '<th scope="col">Nomor Telepon</th>') && str_contains($historyPage->body, 'Alpha Snapshot, Gamma Snapshot') && !str_contains($historyPage->body, '#1 '), 'Authorized history page does not use the approved table and snapshot program names.');

    $activateVersion = $controller->activateQuizVersion(
        adminCampaignRequest('POST', '/admin/campaigns/' . $campaignA . '/quiz-versions/' . $versionA2->id . '/activate', [], ['csrf_token' => $csrf, 'confirmation' => 'AKTIFKAN']),
        (string) $campaignA,
        (string) $versionA2->id,
    );
    $activatedBatches = $adminRepository->listBatchesForCampaign($schoolA, $campaignA);
    adminCampaignAssert(
        $activateVersion->status === 302
            && $adminRepository->findCampaignForSchool($schoolA, $campaignA)?->quizVersionId === $versionA2->id
            && count($activatedBatches) === 3
            && $activatedBatches[1]->status === 'closed'
            && $activatedBatches[1]->quizVersionId === $versionA->id
            && $activatedBatches[2]->status === 'active'
            && $activatedBatches[2]->quizVersionId === $versionA2->id,
        'Authorized published version activation did not preserve historical batches and select the new active version.',
    );

    $script = (string) file_get_contents(SMK_MATCH_ROOT . '/public/assets/js/admin-smart-qr.js');
    adminCampaignAssert(str_contains($script, 'SmkMatchQrSvg.render') && str_contains($script, 'new Blob') && str_contains($script, 'image/svg+xml'), 'Local SVG QR contract is missing.');
    foreach (['fetch(', 'XMLHttpRequest', 'https://', '.png'] as $forbidden) {
        adminCampaignAssert(!str_contains($script, $forbidden), 'Admin QR script contains forbidden behavior: ' . $forbidden);
    }
} finally {
    adminCampaignResetSession();
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . ADMIN_CAMPAIGN_HISTORY_TEST_DATABASE);
    }
    putenv('DB_PASSWORD');
}

echo "Admin campaign/history/QR integration tests passed.\n";
