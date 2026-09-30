<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\SmartLinkRepository;
use App\Core\SmartLinkService;

const PARTICIPANT_REAL_SUBMISSION_TEST_DATABASE = 'smk_match_g11_r11_test';

function r11Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function r11ApplyMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $sql = file_get_contents($path);
        r11Assert($sql !== false, 'Migration could not be read.');

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->exec($statement);
        }
    }
}

/** @return array<string, int> */
function r11PersistenceCounts(PDO $connection): array
{
    $counts = [];
    foreach (['attempts', 'responses', 'results', 'result_scores', 'result_tied_programs'] as $table) {
        $counts[$table] = (int) $connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    return $counts;
}

function r11VisitorUuid(string $setCookie): string
{
    preg_match('/^smk_match_visitor=([^;]+)/', $setCookie, $matches);

    return $matches[1] ?? '';
}

/** @param array<string, mixed> $payload @param array<string, string> $cookies */
function r11JsonRequest(string $method, string $path, array $payload, array $cookies = [], array $headers = []): Request
{
    return new Request(
        $method,
        $path,
        array_merge(['Content-Type' => 'application/json'], $headers),
        json_encode($payload, JSON_THROW_ON_ERROR),
        $cookies,
    );
}

$script = file_get_contents(SMK_MATCH_ROOT . '/public/assets/js/participant-quiz.js');
r11Assert(is_string($script), 'Participant JavaScript could not be read.');
foreach ([
    'submissionInFlight: false',
    "setState('SUBMITTING')",
    "SUBMITTING: () => renderLoading('Menyimpan jawabanmu...')",
    'const submitRealAnswers = async () =>',
    "if (!realMode || state.submissionInFlight)",
    'const answers = questions.map((question) => ({',
    'question_id: question.id',
    'option_id: state.answers[question.id]',
    '/api/public/submit/${encodeURIComponent(attemptUuid)}',
    "method: 'POST'",
    "credentials: 'same-origin'",
    "'Content-Type': 'application/json'",
    'response.status !== 201 && response.status !== 200',
    "payload.status !== 'completed'",
    'payload.attempt_uuid !== attemptUuid',
    'payload.already_completed === false',
    'payload.already_completed === true',
    'state.submissionInFlight = false',
    'submitRealAnswers);',
] as $required) {
    r11Assert(str_contains($script, $required), 'Missing R11 JavaScript contract: ' . $required);
}
r11Assert(
    preg_match('/if\s*\(\s*realMode\s*\)\s*\{\s*submitRealAnswers\s*\(\s*\)\s*;\s*return\s*;\s*\}\s*setState\s*\(\s*[\'\"]ANALYZING[\'\"]\s*\)\s*;/', $script) === 1,
    'Real-mode final submission branch or fixture analyzing fallback is missing.',
);
r11Assert(str_contains($script, 'const hasCompleteAnswers = () => questions.length > 0'), 'Full-answer guard is missing.');
r11Assert(!str_contains($script, 'Object.entries(state.answers)') && !str_contains($script, 'Object.keys(state.answers)'), 'Answer order must use quiz question order.');
r11Assert(!str_contains($script, 'document.cookie') && !str_contains($script, 'localStorage'), 'Participant submission must not read cookies or use local storage.');
r11Assert(str_contains($script, 'window.sessionStorage.setItem(storageKey(), attemptUuid)'), 'Only attempt UUID session storage is missing.');
foreach (['raw_score', 'raw_scores', 'normalized_percentage', 'dominant_program', 'tied_programs', 'weights', '/api/public/result', 'fetch(`/result'] as $forbidden) {
    r11Assert(!str_contains($script, $forbidden), 'Forbidden R11 browser contract: ' . $forbidden);
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
r11Assert($host === '127.0.0.1' && $port === '3306' && $databaseName === PARTICIPANT_REAL_SUBMISSION_TEST_DATABASE, 'Unsafe test database configuration.');
r11Assert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('DROP DATABASE IF EXISTS ' . PARTICIPANT_REAL_SUBMISSION_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . PARTICIPANT_REAL_SUBMISSION_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $config = Config::load(SMK_MATCH_ROOT);
    $database = new Database($config);
    $connection = $database->connection();
    r11ApplyMigrations($connection);

    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('a1100000-0000-4000-8000-000000000001', 'R11 School', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolId = (int) $connection->lastInsertId();
    $programIds = [];
    foreach (['ALPHA', 'BETA', 'GAMMA'] as $code) {
        $connection->prepare('INSERT INTO programs (school_id, name, short_name, created_at, updated_at) VALUES (:school, :name, :code, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['school' => $schoolId, 'name' => 'R11 ' . $code, 'code' => $code]);
        $programIds[] = (int) $connection->lastInsertId();
    }
    $connection->exec("INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES ({$schoolId}, 'R11 Quiz', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $quizId = (int) $connection->lastInsertId();
    $connection->prepare("INSERT INTO quiz_versions (quiz_id, version_number, status, name, published_at, created_at) VALUES (:quiz, 1, 'published', 'R11 Version', UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute(['quiz' => $quizId]);
    $versionId = (int) $connection->lastInsertId();
    $membership = $connection->prepare('INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at) VALUES (:version, :program, UTC_TIMESTAMP())');
    $presentation = $connection->prepare("INSERT INTO quiz_version_program_presentations (quiz_version_program_id, program_code_snapshot, program_name_snapshot, personality_title_snapshot, mascot_path_snapshot, description_snapshot, skills_snapshot, snapshot_provenance, created_at, updated_at) SELECT :membership_id, short_name, name, personality_title, mascot_path, description, skills_json, 'version_snapshot', UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM programs WHERE id = :program_id");
    foreach ($programIds as $programId) {
        $membership->execute(['version' => $versionId, 'program' => $programId]);
        $presentation->execute(['membership_id' => (int) $connection->lastInsertId(), 'program_id' => $programId]);
    }
    $questionInsert = $connection->prepare('INSERT INTO questions (quiz_version_id, prompt, sort_order, created_at, updated_at) VALUES (:version, :prompt, :order, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $optionInsert = $connection->prepare('INSERT INTO question_options (question_id, option_text, sort_order, created_at) VALUES (:question, :text, :order, UTC_TIMESTAMP())');
    $weightInsert = $connection->prepare('INSERT INTO option_weights (question_option_id, program_id, weight, created_at) VALUES (:option, :program, :weight, UTC_TIMESTAMP())');
    foreach ([1, 2] as $questionOrder) {
        $questionInsert->execute(['version' => $versionId, 'prompt' => 'R11 Question ' . $questionOrder, 'order' => $questionOrder]);
        $questionId = (int) $connection->lastInsertId();
        foreach ([1, 2] as $optionOrder) {
            $optionInsert->execute(['question' => $questionId, 'text' => 'R11 Option ' . $questionOrder . '-' . $optionOrder, 'order' => $optionOrder]);
            $optionId = (int) $connection->lastInsertId();
            foreach ($programIds as $programId) {
                $weightInsert->execute(['option' => $optionId, 'program' => $programId, 'weight' => (float) $optionOrder]);
            }
        }
    }
    $connection->prepare("INSERT INTO campaigns (school_id, quiz_id, quiz_version_id, name, status, created_at, updated_at) VALUES (:school, :quiz, :version, 'R11 Campaign', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute(['school' => $schoolId, 'quiz' => $quizId, 'version' => $versionId]);
    $campaignId = (int) $connection->lastInsertId();
    $connection->prepare("INSERT INTO campaign_batches (campaign_id, batch_number, quiz_version_id, label, status, active_marker, started_at, created_at, updated_at) VALUES (:campaign, 1, :version, 'R11 Batch', 'active', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute(['campaign' => $campaignId, 'version' => $versionId]);
    (new SmartLinkService(new SmartLinkRepository($database), new CampaignRepository($database), new CampaignBatchRepository($database)))->create($schoolId, $campaignId, 'R11 Link', 'r11-submit');

    $routes = require SMK_MATCH_ROOT . '/routes/web.php';
    $router = $routes($config);
    $attemptUuid = 'a1200000-0000-4000-8000-000000000001';
    $start = $router->dispatch(r11JsonRequest('POST', '/api/public/start/r11-submit', ['full_name' => 'R11 Participant', 'origin_school' => null, 'class_name' => null, 'phone' => null, 'marketing_consent' => false], [], ['Idempotency-Key' => $attemptUuid]));
    $startPayload = json_decode($start->body, true, 512, JSON_THROW_ON_ERROR);
    $visitorUuid = r11VisitorUuid((string) ($start->headers['Set-Cookie'] ?? ''));
    r11Assert($start->status === 201 && $startPayload === ['ok' => true, 'attempt_uuid' => $attemptUuid, 'status' => 'started', 'already_started' => false] && preg_match('/^[0-9a-f-]{36}$/i', $visitorUuid) === 1, 'R7 start flow did not create a public attempt.');

    $quizResponse = $router->dispatch(new Request('GET', '/api/public/quiz/' . $attemptUuid, [], '', ['smk_match_visitor' => $visitorUuid]));
    $quizPayload = json_decode($quizResponse->body, true, 512, JSON_THROW_ON_ERROR);
    r11Assert($quizResponse->status === 200 && $quizPayload['ok'] === true && $quizPayload['attempt_uuid'] === $attemptUuid && $quizPayload['status'] === 'started' && is_array($quizPayload['quiz']['questions']), 'R8 quiz delivery failed.');
    $answers = [];
    foreach ($quizPayload['quiz']['questions'] as $question) {
        r11Assert(preg_match('/^question-[1-9][0-9]*$/', $question['id']) === 1 && isset($question['options'][0]['id']) && preg_match('/^option-[1-9][0-9]*$/', $question['options'][0]['id']) === 1, 'R8 did not return public question and option IDs.');
        $answers[] = ['question_id' => $question['id'], 'option_id' => $question['options'][0]['id']];
    }
    r11Assert(count($answers) === 2, 'R8 question order was not available for submission construction.');

    $submit = $router->dispatch(r11JsonRequest('POST', '/api/public/submit/' . $attemptUuid, ['answers' => $answers], ['smk_match_visitor' => $visitorUuid]));
    $submitPayload = json_decode($submit->body, true, 512, JSON_THROW_ON_ERROR);
    r11Assert($submit->status === 201 && $submitPayload === ['ok' => true, 'attempt_uuid' => $attemptUuid, 'status' => 'completed', 'already_completed' => false], 'R10 did not accept exact R8 IDs in R8 question order.');
    r11Assert((string) $connection->query("SELECT status FROM attempts WHERE attempt_uuid = '{$attemptUuid}'")->fetchColumn() === 'completed' && $connection->query("SELECT submitted_at FROM attempts WHERE attempt_uuid = '{$attemptUuid}'")->fetchColumn() !== null, 'R10 did not complete the R7 attempt.');
    $afterSubmit = r11PersistenceCounts($connection);
    r11Assert($afterSubmit['attempts'] === 1 && $afterSubmit['responses'] === count($answers) && $afterSubmit['results'] === 1 && $afterSubmit['result_scores'] === count($programIds), 'R10 did not persist the bridged answers and result.');

    $replay = $router->dispatch(r11JsonRequest('POST', '/api/public/submit/' . $attemptUuid, ['answers' => $answers], ['smk_match_visitor' => $visitorUuid]));
    r11Assert($replay->status === 200 && json_decode($replay->body, true, 512, JSON_THROW_ON_ERROR) === ['ok' => true, 'attempt_uuid' => $attemptUuid, 'status' => 'completed', 'already_completed' => true] && r11PersistenceCounts($connection) === $afterSubmit, 'R10 completed replay is not compatible with R11 retry semantics.');
    r11Assert($router->dispatch(new Request('GET', '/health'))->status === 200, 'Route regression failed.');

    echo "Participant real submission bridge tests passed.\n";
} finally {
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . PARTICIPANT_REAL_SUBMISSION_TEST_DATABASE);
    }
}
