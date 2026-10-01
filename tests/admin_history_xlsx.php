<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

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
use PhpOffice\PhpSpreadsheet\IOFactory;

const ADMIN_HISTORY_XLSX_TEST_DATABASE = 'smk_match_g13_r5_test';

function adminHistoryXlsxAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function adminHistoryXlsxStatements(string $path): array
{
    $contents = file_get_contents($path);
    adminHistoryXlsxAssert($contents !== false, 'Migration could not be read.');

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

function adminHistoryXlsxMigrate(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migration) {
        foreach (adminHistoryXlsxStatements($migration) as $statement) {
            $connection->exec($statement);
        }
    }
}

function adminHistoryXlsxResetSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }

    session_id('');
    $_SESSION = [];
}

/** @param array<string, mixed> $query */
function adminHistoryXlsxRequest(array $query = []): Request
{
    return new Request('GET', '/admin/history/export.xlsx', [], '', [], false, $query);
}

function adminHistoryXlsxWorkbook(string $body): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'smk-history-xlsx-');
    adminHistoryXlsxAssert($path !== false, 'Temporary workbook path could not be created.');

    try {
        file_put_contents($path, $body);

        return IOFactory::load($path);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

/** @return list<string> */
function adminHistoryXlsxRowValues(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, int $columns): array
{
    $values = [];
    for ($column = 1; $column <= $columns; $column++) {
        $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column) . $row;
        $values[] = $sheet->getCell($coordinate)->getValue();
    }

    return $values;
}

/** @return list<string> */
function adminHistoryXlsxParticipantNames(\PhpOffice\PhpSpreadsheet\Spreadsheet $workbook): array
{
    $names = [];
    foreach ($workbook->getSheet(0)->toArray() as $index => $row) {
        if ($index === 0 || !isset($row[0]) || !is_string($row[0])) {
            continue;
        }
        $names[] = $row[0];
    }

    return $names;
}

function adminHistoryXlsxDefinition(): QuizDefinition
{
    return new QuizDefinition('Export quiz', 1, ['ALPHA' => true, 'GAMMA' => true], [[
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
adminHistoryXlsxAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === ADMIN_HISTORY_XLSX_TEST_DATABASE,
    'Unsafe test database configuration.',
);
adminHistoryXlsxAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $admin->exec('DROP DATABASE IF EXISTS ' . ADMIN_HISTORY_XLSX_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . ADMIN_HISTORY_XLSX_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $connection = $database->connection();
    adminHistoryXlsxMigrate($connection);

    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('51515151-5151-4151-8151-515151515151', 'Export School A', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolA = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('52525252-5252-4252-8252-525252525252', 'Export School B', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolB = (int) $connection->lastInsertId();

    $programInsert = $connection->prepare('INSERT INTO programs (school_id, name, short_name, created_at, updated_at) VALUES (:school_id, :name, :code, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $programInsert->execute(['school_id' => $schoolA, 'name' => 'Alpha Snapshot', 'code' => 'ALPHA']);
    $alpha = (int) $connection->lastInsertId();
    $programInsert->execute(['school_id' => $schoolA, 'name' => 'Gamma Snapshot', 'code' => 'GAMMA']);
    $gamma = (int) $connection->lastInsertId();
    $programInsert->execute(['school_id' => $schoolB, 'name' => 'Foreign Program', 'code' => 'ALPHA']);
    $programInsert->execute(['school_id' => $schoolB, 'name' => 'Foreign Gamma', 'code' => 'GAMMA']);

    $hash = password_hash('export-password', PASSWORD_DEFAULT);
    adminHistoryXlsxAssert(is_string($hash), 'Admin password fixture could not be generated.');
    $adminInsert = $connection->prepare('INSERT INTO admins (school_id, name, email, password_hash, is_active, created_at, updated_at) VALUES (:school_id, :name, :email, :password_hash, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $adminInsert->execute(['school_id' => $schoolA, 'name' => 'Export Admin', 'email' => 'export@example.test', 'password_hash' => $hash]);
    $adminId = (int) $connection->lastInsertId();

    $quizInsert = $connection->prepare('INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES (:school_id, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $quizInsert->execute(['school_id' => $schoolA, 'name' => 'Export Quiz']);
    $quizA = (int) $connection->lastInsertId();
    $quizInsert->execute(['school_id' => $schoolB, 'name' => 'Foreign Quiz']);
    $quizB = (int) $connection->lastInsertId();

    $versions = new QuizVersionRepository($database, new QuizVersionProgramPresentationRepository($database));
    $versionService = new QuizVersionService($versions);
    $versionA = $versionService->getOrCreateDraft($quizA, adminHistoryXlsxDefinition());
    $versionService->publish($quizA, $versionA->id);
    $versionB = $versionService->getOrCreateDraft($quizB, adminHistoryXlsxDefinition());
    $versionService->publish($quizB, $versionB->id);

    $campaignInsert = $connection->prepare('INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school_id, :quiz_id, :quiz_version_id, :name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $campaignInsert->execute(['school_id' => $schoolA, 'quiz_id' => $quizA, 'quiz_version_id' => $versionA->id, 'name' => 'Export Campaign', 'status' => 'draft']);
    $campaignA = (int) $connection->lastInsertId();
    $campaignInsert->execute(['school_id' => $schoolB, 'quiz_id' => $quizB, 'quiz_version_id' => $versionB->id, 'name' => 'Foreign Campaign', 'status' => 'draft']);
    $campaignB = (int) $connection->lastInsertId();

    $campaignService = new CampaignService($database, new CampaignRepository($database), new CampaignBatchRepository($database), $versions);
    $batchA = $campaignService->activateCampaign($campaignA, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));
    $batchB = $campaignService->activateCampaign($campaignB, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));

    $connection->exec("INSERT INTO participants (school_id, public_uuid, full_name, origin_school, class_name, phone, marketing_consent, created_at, updated_at) VALUES ({$schoolA}, '53535353-5353-4353-8353-535353535353', 'Export Participant', 'Private Origin', 'XII', '08123456789', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $participant = (int) $connection->lastInsertId();
    $question = (int) $connection->query('SELECT id FROM questions WHERE quiz_version_id = ' . $versionA->id . ' LIMIT 1')->fetchColumn();
    $option = (int) $connection->query('SELECT id FROM question_options WHERE question_id = ' . $question . ' LIMIT 1')->fetchColumn();
    $attemptInsert = $connection->prepare('INSERT INTO attempts (participant_id, campaign_id, campaign_batch_id, quiz_version_id, visitor_uuid, attempt_uuid, source, status, submitted_at, created_at) VALUES (:participant_id, :campaign_id, :batch_id, :quiz_version_id, :visitor_uuid, :attempt_uuid, :source, :status, :submitted_at, :created_at)');
    $attemptInsert->execute(['participant_id' => $participant, 'campaign_id' => $campaignA, 'batch_id' => $batchA->id, 'quiz_version_id' => $versionA->id, 'visitor_uuid' => '54545454-5454-4454-8454-545454545454', 'attempt_uuid' => '55555555-5555-4555-8555-555555555555', 'source' => 'test', 'status' => 'completed', 'submitted_at' => '2026-01-01 17:15:00', 'created_at' => '2026-01-01 17:00:00']);
    $attempt = (int) $connection->lastInsertId();
    $connection->prepare('INSERT INTO responses (attempt_id, question_id, question_option_id, created_at) VALUES (:attempt_id, :question_id, :option_id, UTC_TIMESTAMP())')->execute(['attempt_id' => $attempt, 'question_id' => $question, 'option_id' => $option]);
    $connection->prepare('INSERT INTO results (attempt_id, total_raw_score, dominant_program_id, is_tie, created_at) VALUES (:attempt_id, 2, NULL, 1, UTC_TIMESTAMP())')->execute(['attempt_id' => $attempt]);
    $result = (int) $connection->lastInsertId();
    $scoreInsert = $connection->prepare('INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, display_order, created_at) VALUES (:result_id, :program_id, 1, :percentage, :display_order, UTC_TIMESTAMP())');
    $scoreInsert->execute(['result_id' => $result, 'program_id' => $alpha, 'percentage' => 50, 'display_order' => 1]);
    $scoreInsert->execute(['result_id' => $result, 'program_id' => $gamma, 'percentage' => 50, 'display_order' => 2]);
    $tieInsert = $connection->prepare('INSERT INTO result_tied_programs (result_id, program_id) VALUES (:result_id, :program_id)');
    $tieInsert->execute(['result_id' => $result, 'program_id' => $alpha]);
    $tieInsert->execute(['result_id' => $result, 'program_id' => $gamma]);

    $batchA2 = $campaignService->resetActiveBatch($campaignA);
    $campaignInsert->execute(['school_id' => $schoolA, 'quiz_id' => $quizA, 'quiz_version_id' => $versionA->id, 'name' => 'Other Owned Campaign', 'status' => 'draft']);
    $campaignOther = (int) $connection->lastInsertId();
    $batchOther = $campaignService->activateCampaign($campaignOther, new DateTimeImmutable('2026-01-03 00:00:00 UTC'));

    $participantInsert = $connection->prepare('INSERT INTO participants (school_id, public_uuid, full_name, created_at, updated_at) VALUES (:school_id, :public_uuid, :full_name, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $participantInsert->execute(['school_id' => $schoolA, 'public_uuid' => '56565656-5656-4656-8656-565656565656', 'full_name' => 'Batch Two Participant']);
    $participantBatchTwo = (int) $connection->lastInsertId();
    $participantInsert->execute(['school_id' => $schoolA, 'public_uuid' => '57575757-5757-4757-8757-575757575757', 'full_name' => 'Other Campaign Participant']);
    $participantOther = (int) $connection->lastInsertId();
    $participantInsert->execute(['school_id' => $schoolB, 'public_uuid' => '58585858-5858-4858-8858-585858585858', 'full_name' => 'Foreign Participant']);
    $participantForeign = (int) $connection->lastInsertId();

    $persistCompleted = static function (int $participantId, int $campaignId, int $batchId, string $visitorUuid, string $attemptUuid) use ($connection, $versionA, $question, $option, $alpha, $gamma): void {
        $connection->prepare('INSERT INTO attempts (participant_id, campaign_id, campaign_batch_id, quiz_version_id, visitor_uuid, attempt_uuid, source, status, submitted_at, created_at) VALUES (:participant_id, :campaign_id, :batch_id, :quiz_version_id, :visitor_uuid, :attempt_uuid, :source, :status, :submitted_at, :created_at)')->execute([
            'participant_id' => $participantId,
            'campaign_id' => $campaignId,
            'batch_id' => $batchId,
            'quiz_version_id' => $versionA->id,
            'visitor_uuid' => $visitorUuid,
            'attempt_uuid' => $attemptUuid,
            'source' => 'test',
            'status' => 'completed',
            'submitted_at' => '2026-01-03 00:15:00',
            'created_at' => '2026-01-03 00:00:00',
        ]);
        $attemptId = (int) $connection->lastInsertId();
        $connection->prepare('INSERT INTO responses (attempt_id, question_id, question_option_id, created_at) VALUES (:attempt_id, :question_id, :option_id, UTC_TIMESTAMP())')->execute(['attempt_id' => $attemptId, 'question_id' => $question, 'option_id' => $option]);
        $connection->prepare('INSERT INTO results (attempt_id, total_raw_score, dominant_program_id, is_tie, created_at) VALUES (:attempt_id, 2, NULL, 1, UTC_TIMESTAMP())')->execute(['attempt_id' => $attemptId]);
        $resultId = (int) $connection->lastInsertId();
        $insertScore = $connection->prepare('INSERT INTO result_scores (result_id, program_id, raw_score, normalized_percentage, display_order, created_at) VALUES (:result_id, :program_id, 1, 50, :display_order, UTC_TIMESTAMP())');
        $insertScore->execute(['result_id' => $resultId, 'program_id' => $alpha, 'display_order' => 1]);
        $insertScore->execute(['result_id' => $resultId, 'program_id' => $gamma, 'display_order' => 2]);
        $insertTie = $connection->prepare('INSERT INTO result_tied_programs (result_id, program_id) VALUES (:result_id, :program_id)');
        $insertTie->execute(['result_id' => $resultId, 'program_id' => $alpha]);
        $insertTie->execute(['result_id' => $resultId, 'program_id' => $gamma]);
    };
    $persistCompleted($participantBatchTwo, $campaignA, $batchA2->id, '59595959-5959-4959-8959-595959595959', '60606060-6060-4060-8060-606060606060');
    $persistCompleted($participantOther, $campaignOther, $batchOther->id, '61616161-6161-4161-8161-616161616161', '62626262-6262-4262-8262-626262626262');
    $connection->prepare('INSERT INTO attempts (participant_id, campaign_id, campaign_batch_id, quiz_version_id, visitor_uuid, attempt_uuid, source, status, created_at) VALUES (:participant_id, :campaign_id, :batch_id, :quiz_version_id, :visitor_uuid, :attempt_uuid, :source, :status, UTC_TIMESTAMP())')->execute([
        'participant_id' => $participantForeign,
        'campaign_id' => $campaignB,
        'batch_id' => $batchB->id,
        'quiz_version_id' => $versionB->id,
        'visitor_uuid' => '63636363-6363-4363-8363-636363636363',
        'attempt_uuid' => '64646464-6464-4464-8464-646464646464',
        'source' => 'test',
        'status' => 'started',
    ]);

    $routes = require SMK_MATCH_ROOT . '/routes/web.php';
    $router = $routes($config);
    $session = new AdminSession();

    adminHistoryXlsxResetSession();
    adminHistoryXlsxAssert($router->dispatch(adminHistoryXlsxRequest())->status === 302, 'Unauthenticated XLSX export was accepted.');
    $session->start(false);
    $session->login(['admin_id' => $adminId, 'school_id' => $schoolA]);

    $connection->prepare('UPDATE programs SET name = :name WHERE id = :id')->execute(['name' => 'Mutable Catalog Name', 'id' => $alpha]);
    $before = (int) $connection->query('SELECT COUNT(*) FROM attempts')->fetchColumn();
    $response = $router->dispatch(adminHistoryXlsxRequest([
        'campaign_id' => (string) $campaignA,
        'batch_id' => (string) $batchA->id,
        'from' => '2026-01-02',
        'to' => '2026-01-02',
        'school_id' => (string) $schoolB,
    ]));
    adminHistoryXlsxAssert($response->status === 200, 'Authorized XLSX export failed.');
    adminHistoryXlsxAssert($response->headers['Content-Type'] === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'XLSX content type is incorrect.');
    adminHistoryXlsxAssert(str_contains((string) $response->headers['Content-Disposition'], 'attachment;') && str_contains((string) $response->headers['Content-Disposition'], '.xlsx'), 'XLSX download disposition is missing.');
    adminHistoryXlsxAssert($response->headers['Cache-Control'] === 'no-store', 'XLSX export is cacheable.');
    adminHistoryXlsxAssert((int) $connection->query('SELECT COUNT(*) FROM attempts')->fetchColumn() === $before, 'XLSX export modified persistence.');

    $workbook = adminHistoryXlsxWorkbook($response->body);
    adminHistoryXlsxAssert($workbook->getSheetNames() === ['Participants', 'Results', 'Program Summary'], 'Workbook sheet names are incorrect.');
    adminHistoryXlsxAssert(adminHistoryXlsxRowValues($workbook->getSheet(0), 1, 6) === [
        'Participant name',
        'Campaign name',
        'Batch number',
        'Batch label',
        'Attempt status',
        'Attempt created at',
    ], 'Participants header is incorrect.');
    adminHistoryXlsxAssert(adminHistoryXlsxRowValues($workbook->getSheet(1), 1, 12) === [
        'Participant name',
        'Campaign name',
        'Batch number',
        'Batch label',
        'Attempt status',
        'Attempt created at',
        'Outcome kind',
        'Outcome programs',
        'Display order',
        'Program code',
        'Program name',
        'Normalized percentage',
    ], 'Results header is incorrect.');
    adminHistoryXlsxAssert(adminHistoryXlsxRowValues($workbook->getSheet(2), 1, 4) === [
        'Program code',
        'Program name',
        'Dominant count',
        'Average normalized percentage',
    ], 'Program Summary header is incorrect.');
    adminHistoryXlsxAssert($workbook->getSheet(0)->getCell('A2')->getValue() === 'Export Participant', 'Participants sheet lacks filtered participant data.');
    adminHistoryXlsxAssert($workbook->getSheet(1)->getCell('H2')->getValue() === 'ALPHA, GAMMA', 'Tie-safe outcome programs were not exported.');
    adminHistoryXlsxAssert($workbook->getSheet(1)->getCell('I2')->getValue() === 1 && $workbook->getSheet(1)->getCell('J2')->getValue() === 'ALPHA', 'Persisted ranking order was not exported.');
    adminHistoryXlsxAssert($workbook->getSheet(2)->getCell('A2')->getValue() === 'ALPHA' && $workbook->getSheet(2)->getCell('B2')->getValue() === 'Alpha Snapshot', 'Snapshot-backed summary was not exported.');
    $serializedWorkbook = json_encode([
        $workbook->getSheet(0)->toArray(),
        $workbook->getSheet(1)->toArray(),
        $workbook->getSheet(2)->toArray(),
    ], JSON_THROW_ON_ERROR);
    foreach (['08123456789', 'Private Origin', 'marketing_consent', 'visitor_uuid', 'attempt_uuid', 'responses'] as $forbidden) {
        adminHistoryXlsxAssert(!str_contains($serializedWorkbook, $forbidden), 'XLSX response leaked sensitive data: ' . $forbidden);
    }
    $workbook->disconnectWorksheets();

    $allHistoryWorkbook = adminHistoryXlsxWorkbook($router->dispatch(adminHistoryXlsxRequest())->body);
    $allHistoryParticipants = adminHistoryXlsxParticipantNames($allHistoryWorkbook);
    adminHistoryXlsxAssert(
        in_array('Export Participant', $allHistoryParticipants, true)
            && in_array('Batch Two Participant', $allHistoryParticipants, true)
            && in_array('Other Campaign Participant', $allHistoryParticipants, true)
            && !in_array('Foreign Participant', $allHistoryParticipants, true),
        'Authenticated all-history XLSX export did not retain school scope.',
    );
    $allHistoryWorkbook->disconnectWorksheets();

    $campaignWorkbook = adminHistoryXlsxWorkbook($router->dispatch(adminHistoryXlsxRequest([
        'campaign_id' => (string) $campaignA,
    ]))->body);
    $campaignParticipants = adminHistoryXlsxParticipantNames($campaignWorkbook);
    adminHistoryXlsxAssert(
        in_array('Export Participant', $campaignParticipants, true)
            && in_array('Batch Two Participant', $campaignParticipants, true)
            && !in_array('Other Campaign Participant', $campaignParticipants, true),
        'Independent campaign XLSX filter did not exclude unrelated campaign history.',
    );
    $campaignWorkbook->disconnectWorksheets();

    $batchWorkbook = adminHistoryXlsxWorkbook($router->dispatch(adminHistoryXlsxRequest([
        'campaign_id' => (string) $campaignA,
        'batch_id' => (string) $batchA2->id,
    ]))->body);
    $batchParticipants = adminHistoryXlsxParticipantNames($batchWorkbook);
    adminHistoryXlsxAssert(
        $batchParticipants === ['Batch Two Participant'],
        'Independent batch XLSX filter did not exclude other batch history.',
    );
    $batchWorkbook->disconnectWorksheets();

    adminHistoryXlsxAssert($router->dispatch(adminHistoryXlsxRequest(['campaign_id' => (string) $campaignB]))->status === 404, 'Cross-school XLSX export was accepted.');
    adminHistoryXlsxAssert($router->dispatch(adminHistoryXlsxRequest(['campaign_id' => (string) $campaignA, 'batch_id' => (string) $batchB->id]))->status === 404, 'Cross-campaign batch XLSX export was accepted.');
    adminHistoryXlsxAssert($router->dispatch(adminHistoryXlsxRequest(['from' => '2026-02-30']))->status === 422, 'Invalid XLSX date filter was accepted.');
    adminHistoryXlsxAssert((glob(SMK_MATCH_ROOT . '/*.xlsx') ?: []) === [], 'XLSX export persisted a workbook in the repository.');
} finally {
    adminHistoryXlsxResetSession();
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . ADMIN_HISTORY_XLSX_TEST_DATABASE);
    }
    putenv('DB_PASSWORD');
}

echo "Admin history XLSX integration tests passed.\n";
