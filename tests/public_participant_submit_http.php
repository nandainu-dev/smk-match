<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\AttemptRepository;
use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\CampaignService;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\QuizVersionRepository;

const PUBLIC_SUBMIT_HTTP_TEST_DATABASE = 'smk_match_g11_r10_test';

function submitHttpAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function submitHttpMigrations(PDO $connection): void { foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $path) foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents($path)))) as $sql) $connection->exec($sql); }
function submitHttpCount(PDO $connection, string $table): int { return (int) $connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
/** @return array<string, int> */
function submitHttpState(PDO $connection): array { $state = []; foreach (['responses', 'results', 'result_scores', 'result_tied_programs'] as $table) $state[$table] = submitHttpCount($connection, $table); return $state; }
function submitHttpRequest(string $uuid, ?string $visitor, string $body, string $type = 'application/json'): Request { return new Request('POST', '/api/public/submit/' . $uuid, ['Content-Type' => $type], $body, $visitor === null ? [] : ['smk_match_visitor' => $visitor]); }
/** @param list<array{question_id:string,option_id:string}> $answers */
function submitHttpPayload(array $answers): string { return json_encode(['answers' => $answers], JSON_THROW_ON_ERROR); }

$host = getenv('DB_HOST'); $port = getenv('DB_PORT'); $databaseName = getenv('DB_DATABASE'); $username = getenv('DB_USERNAME'); $password = getenv('DB_PASSWORD');
submitHttpAssert($host === '127.0.0.1' && $port === '3306' && $databaseName === PUBLIC_SUBMIT_HTTP_TEST_DATABASE, 'Unsafe test database configuration.');
submitHttpAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');
$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('DROP DATABASE IF EXISTS ' . PUBLIC_SUBMIT_HTTP_TEST_DATABASE); $admin->exec('CREATE DATABASE ' . PUBLIC_SUBMIT_HTTP_TEST_DATABASE . ' CHARACTER SET utf8mb4');
    $config = Config::load(SMK_MATCH_ROOT); $database = new Database($config); $connection = $database->connection(); submitHttpMigrations($connection);
    $connection->exec("INSERT INTO schools (public_uuid,name,created_at,updated_at) VALUES ('a1000000-0000-4000-8000-000000000001','Submit School',UTC_TIMESTAMP(),UTC_TIMESTAMP())"); $schoolId = (int) $connection->lastInsertId();
    $programIds=[]; foreach (['ALPHA','BETA','GAMMA'] as $code) { $connection->prepare('INSERT INTO programs (school_id,name,short_name,created_at,updated_at) VALUES (:school,:name,:code,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['school'=>$schoolId,'name'=>$code,'code'=>$code]); $programIds[]=(int)$connection->lastInsertId(); }
    $connection->exec("INSERT INTO quizzes (school_id,name,created_at,updated_at) VALUES ({$schoolId},'Submit Quiz',UTC_TIMESTAMP(),UTC_TIMESTAMP())"); $quizId=(int)$connection->lastInsertId();
    $connection->prepare("INSERT INTO quiz_versions (quiz_id,version_number,status,name,published_at,created_at) VALUES (:quiz,1,'published','Submit V1',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['quiz'=>$quizId]); $versionId=(int)$connection->lastInsertId();
    $insertV1Membership = $connection->prepare('INSERT INTO quiz_version_programs (quiz_version_id,program_id,created_at) VALUES (:version,:program,UTC_TIMESTAMP())');
    $insertPresentation = $connection->prepare("INSERT INTO quiz_version_program_presentations (quiz_version_program_id, program_code_snapshot, program_name_snapshot, personality_title_snapshot, mascot_path_snapshot, description_snapshot, skills_snapshot, snapshot_provenance, created_at, updated_at) SELECT :membership_id, short_name, name, personality_title, mascot_path, description, skills_json, 'version_snapshot', UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM programs WHERE id = :program_id");
    foreach ($programIds as $programId) { $insertV1Membership->execute(['version'=>$versionId,'program'=>$programId]); $insertPresentation->execute(['membership_id'=>(int)$connection->lastInsertId(),'program_id'=>$programId]); }
    $questions=[]; for ($order=1;$order<=2;$order++) { $connection->prepare('INSERT INTO questions (quiz_version_id,prompt,sort_order,created_at,updated_at) VALUES (:version,:prompt,:order,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['version'=>$versionId,'prompt'=>'Question '.$order,'order'=>$order]); $questionId=(int)$connection->lastInsertId(); $options=[]; for ($optionOrder=1;$optionOrder<=2;$optionOrder++) { $connection->prepare('INSERT INTO question_options (question_id,option_text,sort_order,created_at) VALUES (:question,:text,:order,UTC_TIMESTAMP())')->execute(['question'=>$questionId,'text'=>'Option '.$order.'-'.$optionOrder,'order'=>$optionOrder]); $optionId=(int)$connection->lastInsertId(); foreach ($programIds as $programId) $connection->prepare('INSERT INTO option_weights (question_option_id,program_id,weight,created_at) VALUES (:option,:program,:weight,UTC_TIMESTAMP())')->execute(['option'=>$optionId,'program'=>$programId,'weight'=>(float)$optionOrder]); $options[]=$optionId; } $questions[]=['id'=>$questionId,'options'=>$options]; }
    $connection->prepare("INSERT INTO campaigns (school_id,quiz_id,quiz_version_id,name,status,created_at,updated_at) VALUES (:school,:quiz,:version,'Submit Campaign','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['school'=>$schoolId,'quiz'=>$quizId,'version'=>$versionId]); $campaignId=(int)$connection->lastInsertId();
    $connection->prepare("INSERT INTO campaign_batches (campaign_id,batch_number,quiz_version_id,label,status,active_marker,started_at,created_at,updated_at) VALUES (:campaign,1,:version,'Batch 1','active',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['campaign'=>$campaignId,'version'=>$versionId]); $batchId=(int)$connection->lastInsertId();
    $connection->prepare("INSERT INTO participants (school_id,public_uuid,full_name,marketing_consent,created_at,updated_at) VALUES (:school,'a2000000-0000-4000-8000-000000000001','Submit Participant',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['school'=>$schoolId]); $participantId=(int)$connection->lastInsertId();
    $visitor='a3000000-0000-4000-8000-000000000001'; $attemptUuid='a4000000-0000-4000-8000-000000000001'; $attempt=(new AttemptRepository($database))->create($participantId,$campaignId,$batchId,$versionId,$visitor,$attemptUuid,null,new DateTimeImmutable('now',new DateTimeZone('UTC')));
    $routes=require SMK_MATCH_ROOT.'/routes/web.php'; $router=$routes($config); $answers=[['question_id'=>'question-'.$questions[0]['id'],'option_id'=>'option-'.$questions[0]['options'][0]],['question_id'=>'question-'.$questions[1]['id'],'option_id'=>'option-'.$questions[1]['options'][0]]]; $payload=submitHttpPayload($answers);
    $before=submitHttpState($connection);
    foreach ([submitHttpRequest('a4000000-0000-4000-800000000001',$visitor,$payload),submitHttpRequest('a40000000-0000-4000-8000-000000000099',$visitor,$payload),submitHttpRequest($attemptUuid,null,$payload),submitHttpRequest($attemptUuid,'bad',$payload),submitHttpRequest($attemptUuid,'a3000000-0000-4000-8000-000000000099',$payload),submitHttpRequest($attemptUuid,$visitor,$payload,'text/plain'),submitHttpRequest($attemptUuid,$visitor,'{bad')] as $request) { $response=$router->dispatch($request); submitHttpAssert(in_array($response->status,[400,404,415],true), 'Ownership or transport validation failed.'); submitHttpAssert($before===submitHttpState($connection),'Invalid request mutated persistence.'); }
    foreach ([submitHttpPayload([]), '{}', '{"answers":null}', '{"answers":{}}', submitHttpPayload([['question_id'=>'question-01','option_id'=>'option-1']]), submitHttpPayload([['question_id'=>'question-1','option_id'=>'option-01']]), submitHttpPayload([['question_id'=>'question-999999999999999999999999','option_id'=>'option-1']]), submitHttpPayload([['question_id'=>'question-'.$questions[0]['id'],'option_id'=>'option-'.$questions[0]['options'][0]],['question_id'=>'question-'.$questions[0]['id'],'option_id'=>'option-'.$questions[0]['options'][1]]]), submitHttpPayload([['question_id'=>'question-'.$questions[0]['id'],'option_id'=>'option-'.$questions[0]['options'][0]]]), submitHttpPayload([['question_id'=>'question-99999','option_id'=>'option-'.$questions[0]['options'][0]]]), submitHttpPayload([['question_id'=>'question-'.$questions[0]['id'],'option_id'=>'option-'.$questions[1]['options'][0]]])] as $invalid) { $response=$router->dispatch(submitHttpRequest($attemptUuid,$visitor,$invalid)); submitHttpAssert($response->status===422,'Answer validation did not return 422.'); submitHttpAssert($before===submitHttpState($connection),'Invalid answers mutated persistence.'); }
    $badItems = [submitHttpPayload(['bad']), submitHttpPayload([['option_id' => 'option-1']]), submitHttpPayload([['question_id' => 'question-1']]), submitHttpPayload([['question_id' => 1, 'option_id' => 'option-1']]), submitHttpPayload([['question_id' => 'question-1', 'option_id' => 1]]), submitHttpPayload([['question_id' => '', 'option_id' => 'option-1']]), submitHttpPayload([['question_id' => 'question-1', 'option_id' => '']])];
    foreach ($badItems as $badItem) { $bad=$router->dispatch(submitHttpRequest($attemptUuid,$visitor,$badItem)); submitHttpAssert($bad->status===422 && $before===submitHttpState($connection),'Malformed item validation failed.'); }
    $connection->prepare("INSERT INTO quiz_versions (quiz_id,version_number,status,name,published_at,created_at) VALUES (:quiz,3,'published','Cross Version',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['quiz'=>$quizId]); $crossVersion=(int)$connection->lastInsertId(); $connection->prepare('INSERT INTO questions (quiz_version_id,prompt,sort_order,created_at,updated_at) VALUES (:v,:p,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['v'=>$crossVersion,'p'=>'Cross version question']); $crossQuestion=(int)$connection->lastInsertId(); $connection->prepare('INSERT INTO question_options (question_id,option_text,sort_order,created_at) VALUES (:q,:t,1,UTC_TIMESTAMP())')->execute(['q'=>$crossQuestion,'t'=>'Cross version option']); $crossOption=(int)$connection->lastInsertId(); $cross=$router->dispatch(submitHttpRequest($attemptUuid,$visitor,submitHttpPayload([['question_id'=>'question-'.$questions[0]['id'],'option_id'=>'option-'.$questions[0]['options'][0]],['question_id'=>'question-'.$crossQuestion,'option_id'=>'option-'.$crossOption]]))); submitHttpAssert($cross->status===422 && $before===submitHttpState($connection),'Cross-version question validation failed.');
    $response=$router->dispatch(submitHttpRequest($attemptUuid,$visitor,$payload)); $json=json_decode($response->body,true,512,JSON_THROW_ON_ERROR); submitHttpAssert($response->status===201 && $json===['ok'=>true,'attempt_uuid'=>$attemptUuid,'status'=>'completed','already_completed'=>false],'First submission response invalid.'); submitHttpAssert((new AttemptRepository($database))->findById($attempt->id)?->status==='completed' && submitHttpCount($connection,'responses')===2 && submitHttpCount($connection,'results')===1 && submitHttpCount($connection,'result_scores')===3,'First submission persistence invalid.');
    $persisted=submitHttpState($connection); foreach ([$payload,submitHttpPayload([['question_id'=>'question-'.$questions[0]['id'],'option_id'=>'option-'.$questions[0]['options'][1]],['question_id'=>'question-'.$questions[1]['id'],'option_id'=>'option-'.$questions[1]['options'][1]]])] as $retryPayload) { $retry=$router->dispatch(submitHttpRequest($attemptUuid,$visitor,$retryPayload)); submitHttpAssert($retry->status===200 && json_decode($retry->body,true)['already_completed']===true && $persisted===submitHttpState($connection),'Completed replay mutated persistence.'); }
    $makeHistorical = function (string $participantUuid, string $visitorUuid, string $attemptValue) use ($connection, $database, $schoolId, $campaignId, $batchId, $versionId): array { $connection->prepare("INSERT INTO participants (school_id,public_uuid,full_name,marketing_consent,created_at,updated_at) VALUES (:school,:uuid,'Historical',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['school'=>$schoolId,'uuid'=>$participantUuid]); $participantId=(int)$connection->lastInsertId(); return [(new AttemptRepository($database))->create($participantId,$campaignId,$batchId,$versionId,$visitorUuid,$attemptValue,null,new DateTimeImmutable('now',new DateTimeZone('UTC'))), $visitorUuid]; };
    [$closedAttempt,$closedVisitor]=$makeHistorical('a2000000-0000-4000-8000-000000000002','a3000000-0000-4000-8000-000000000002','a4000000-0000-4000-8000-000000000002'); submitHttpAssert($closedAttempt->status==='started' && submitHttpState($connection)===$persisted,'Closed-batch precondition invalid.'); $nextBatch=(new CampaignService($database,new CampaignRepository($database),new CampaignBatchRepository($database),new QuizVersionRepository($database)))->resetActiveBatch($campaignId,new DateTimeImmutable('now',new DateTimeZone('UTC'))); $closedBatch=(new CampaignBatchRepository($database))->findById($batchId); submitHttpAssert($closedBatch?->status==='closed' && $nextBatch->status==='active' && $nextBatch->quizVersionId===$versionId,'Supported batch reset failed.'); $closedResponse=$router->dispatch(submitHttpRequest($closedAttempt->attemptUuid,$closedVisitor,$payload)); submitHttpAssert($closedResponse->status===201 && json_decode($closedResponse->body,true)===['ok'=>true,'attempt_uuid'=>$closedAttempt->attemptUuid,'status'=>'completed','already_completed'=>false],'Closed historical batch submission failed.');
    $connection->prepare("INSERT INTO campaigns (school_id,quiz_id,quiz_version_id,name,status,created_at,updated_at) VALUES (:school,:quiz,:version,'Version Reset Campaign','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['school'=>$schoolId,'quiz'=>$quizId,'version'=>$versionId]);
    $caseBCampaignId = (int) $connection->lastInsertId();
    $connection->prepare("INSERT INTO campaign_batches (campaign_id,batch_number,quiz_version_id,label,status,active_marker,started_at,created_at,updated_at) VALUES (:campaign,1,:version,'Case B Batch 1','active',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['campaign'=>$caseBCampaignId,'version'=>$versionId]);
    $caseBBatchOneId = (int) $connection->lastInsertId();
    $connection->prepare("INSERT INTO participants (school_id,public_uuid,full_name,marketing_consent,created_at,updated_at) VALUES (:school,'a2000000-0000-4000-8000-000000000004','Version Reset',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['school'=>$schoolId]);
    $caseBParticipantId = (int) $connection->lastInsertId();
    $caseBVisitor = 'a3000000-0000-4000-8000-000000000004';
    $caseBAttemptUuid = 'a4000000-0000-4000-8000-000000000004';
    $caseBAttempt = (new AttemptRepository($database))->create($caseBParticipantId, $caseBCampaignId, $caseBBatchOneId, $versionId, $caseBVisitor, $caseBAttemptUuid, null, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    submitHttpAssert($caseBAttempt->status === 'started' && $caseBAttempt->quizVersionId === $versionId && $caseBAttempt->campaignBatchId === $caseBBatchOneId && submitHttpCount($connection, 'responses') === 4, 'Case B Version 1 / Batch 1 initial state is invalid.');

    $connection->prepare("INSERT INTO quiz_versions (quiz_id,version_number,status,name,published_at,created_at) VALUES (:quiz,2,'published','Complete V2',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['quiz'=>$quizId]);
    $completeV2 = (int) $connection->lastInsertId();
    $insertMembership = $connection->prepare('INSERT INTO quiz_version_programs (quiz_version_id,program_id,created_at) VALUES (:version,:program,UTC_TIMESTAMP())');
    foreach ($programIds as $programId) {
        $insertMembership->execute(['version'=>$completeV2,'program'=>$programId]);
        $insertPresentation->execute(['membership_id' => (int) $connection->lastInsertId(), 'program_id' => $programId]);
    }
    $insertQuestion = $connection->prepare('INSERT INTO questions (quiz_version_id,prompt,sort_order,created_at,updated_at) VALUES (:version,:prompt,:order,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $insertOption = $connection->prepare('INSERT INTO question_options (question_id,option_text,sort_order,created_at) VALUES (:question,:text,:order,UTC_TIMESTAMP())');
    $insertWeight = $connection->prepare('INSERT INTO option_weights (question_option_id,program_id,weight,created_at) VALUES (:option,:program,:weight,UTC_TIMESTAMP())');
    $v2Questions = [];
    foreach ([1, 2] as $questionOrder) {
        $insertQuestion->execute(['version'=>$completeV2,'prompt'=>'V2 Question '.$questionOrder,'order'=>$questionOrder]);
        $v2QuestionId = (int) $connection->lastInsertId();
        $v2OptionIds = [];
        foreach ([1, 2] as $optionOrder) {
            $insertOption->execute(['question'=>$v2QuestionId,'text'=>'V2 Option '.$questionOrder.'-'.$optionOrder,'order'=>$optionOrder]);
            $v2OptionId = (int) $connection->lastInsertId();
            foreach ($programIds as $programId) {
                $insertWeight->execute(['option'=>$v2OptionId,'program'=>$programId,'weight'=>(float) $optionOrder]);
            }
            $v2OptionIds[] = $v2OptionId;
        }
        $v2Questions[] = ['id'=>$v2QuestionId,'options'=>$v2OptionIds];
    }
    $v1QuestionIds = array_column($questions, 'id');
    $v1OptionIds = array_merge(...array_column($questions, 'options'));
    $expectedCaseBV1OptionIds = [
        (int) $questions[0]['options'][0],
        (int) $questions[1]['options'][0],
    ];
    sort($expectedCaseBV1OptionIds, SORT_NUMERIC);
    $v2QuestionIds = array_column($v2Questions, 'id');
    $v2OptionIds = array_merge(...array_column($v2Questions, 'options'));
    submitHttpAssert(array_intersect($v1QuestionIds, $v2QuestionIds) === [] && array_intersect($v1OptionIds, $v2OptionIds) === [], 'Version 2 reused Version 1 question or option IDs.');
    $versionRepository = new QuizVersionRepository($database);
    $hydratedV2 = $versionRepository->findById($completeV2);
    submitHttpAssert($hydratedV2?->isPublished() === true && count($hydratedV2->definition()->programs) === count($programIds) && count($hydratedV2->definition()->questions) === 2, 'Version 2 did not hydrate as a complete published snapshot.');
    $hydratedV2->definition()->validate();
    foreach ($hydratedV2->definition()->questions as $hydratedQuestion) {
        submitHttpAssert(count($hydratedQuestion['options']) === 2, 'Version 2 question does not have both options.');
        foreach ($hydratedQuestion['options'] as $hydratedOption) {
            submitHttpAssert(count($hydratedOption['weights']) === count($programIds), 'Version 2 option weights are incomplete.');
        }
    }

    $connection->prepare('UPDATE campaigns SET quiz_version_id=:version WHERE id=:id')->execute(['version'=>$completeV2,'id'=>$caseBCampaignId]);
    $campaignRepository = new CampaignRepository($database);
    $attemptRepository = new AttemptRepository($database);
    $caseBPreResetAttempt = $attemptRepository->findById($caseBAttempt->id);
    submitHttpAssert($campaignRepository->findById($caseBCampaignId)?->quizVersionId === $completeV2 && $caseBPreResetAttempt?->status === 'started' && $caseBPreResetAttempt->quizVersionId === $versionId && $caseBPreResetAttempt->campaignBatchId === $caseBBatchOneId, 'Campaign version change rewrote Case B history.');
    $campaignBatches = new CampaignBatchRepository($database);
    $caseBBatchTwo = (new CampaignService($database, $campaignRepository, $campaignBatches, $versionRepository))->resetActiveBatch($caseBCampaignId, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $caseBBatchOne = $campaignBatches->findById($caseBBatchOneId);
    $caseBPostResetAttempt = $attemptRepository->findById($caseBAttempt->id);
    submitHttpAssert($caseBBatchOne?->status === 'closed' && $caseBBatchTwo->status === 'active' && $caseBBatchTwo->quizVersionId === $completeV2 && $caseBPostResetAttempt?->status === 'started' && $caseBPostResetAttempt->campaignBatchId === $caseBBatchOneId && $caseBPostResetAttempt->quizVersionId === $versionId, 'Case B version reset did not preserve Version 1 / Batch 1 history.');

    $caseBResponse = $router->dispatch(submitHttpRequest($caseBAttemptUuid, $caseBVisitor, $payload));
    submitHttpAssert($caseBResponse->status === 201 && json_decode($caseBResponse->body, true, 512, JSON_THROW_ON_ERROR) === ['ok'=>true,'attempt_uuid'=>$caseBAttemptUuid,'status'=>'completed','already_completed'=>false], 'Case B Version 1 submission response is invalid.');
    $caseBCompletedAttempt = $attemptRepository->findById($caseBAttempt->id);
    $persistedQuestions = array_map('intval', $connection->query('SELECT question_id FROM responses WHERE attempt_id='.(int) $caseBAttempt->id)->fetchAll(PDO::FETCH_COLUMN));
    $persistedOptions = array_map('intval', $connection->query('SELECT question_option_id FROM responses WHERE attempt_id='.(int) $caseBAttempt->id)->fetchAll(PDO::FETCH_COLUMN));
    sort($persistedOptions, SORT_NUMERIC);
    submitHttpAssert($caseBCompletedAttempt?->status === 'completed' && $caseBCompletedAttempt->submittedAt !== null && count($persistedQuestions) === count($v1QuestionIds) && count($persistedOptions) === count($v1QuestionIds) && array_diff($persistedQuestions, $v1QuestionIds) === [] && array_diff($persistedOptions, $v1OptionIds) === [] && $persistedOptions === $expectedCaseBV1OptionIds && array_intersect($persistedQuestions, $v2QuestionIds) === [] && array_intersect($persistedOptions, $v2OptionIds) === [], 'Case B persistence did not retain only selected Version 1 response IDs.');
    $caseBResult = $connection->query('SELECT id, dominant_program_id, is_tie FROM results WHERE attempt_id='.(int) $caseBAttempt->id)->fetch(PDO::FETCH_ASSOC);
    submitHttpAssert(is_array($caseBResult) && submitHttpCount($connection, 'results') === 3 && submitHttpCount($connection, 'responses') === 6 && (int) $connection->query('SELECT COUNT(*) FROM result_scores WHERE result_id='.(int) $caseBResult['id'])->fetchColumn() === count($programIds), 'Case B result or score persistence is incomplete.');
    $caseBTieCount = (int) $connection->query('SELECT COUNT(*) FROM result_tied_programs WHERE result_id='.(int) $caseBResult['id'])->fetchColumn();
    submitHttpAssert(((int) $caseBResult['is_tie'] === 1 && $caseBResult['dominant_program_id'] === null && $caseBTieCount >= 2) || ((int) $caseBResult['is_tie'] === 0 && $caseBResult['dominant_program_id'] !== null && $caseBTieCount === 0), 'Case B result tie persistence is inconsistent.');
    $connection->prepare("INSERT INTO participants (school_id,public_uuid,full_name,marketing_consent,created_at,updated_at) VALUES (:school,'a2000000-0000-4000-8000-000000000003','Draft Historical',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['school'=>$schoolId]); $draftParticipant=(int)$connection->lastInsertId(); $draftVisitor='a3000000-0000-4000-8000-000000000003'; $draftUuid='a4000000-0000-4000-8000-000000000003'; $draftAttempt=(new AttemptRepository($database))->create($draftParticipant,$campaignId,$batchId,$versionId,$draftVisitor,$draftUuid,null,new DateTimeImmutable('now',new DateTimeZone('UTC'))); $connection->prepare("UPDATE campaigns SET status='draft' WHERE id=:id")->execute(['id'=>$campaignId]); submitHttpAssert((new CampaignRepository($database))->findById($campaignId)?->status==='draft' && $draftAttempt->status==='started','Draft historical precondition invalid.'); $draftResponse=$router->dispatch(submitHttpRequest($draftUuid,$draftVisitor,$payload)); submitHttpAssert($draftResponse->status===201 && submitHttpCount($connection,'results')===4,'Draft campaign historical submission failed.');
    submitHttpAssert($router->dispatch(new Request('GET','/health'))->status===200 && $router->dispatch(new Request('GET','/api/public/submit/'.$attemptUuid))->status===404,'Route regression failed.');
    echo "Public participant submit HTTP tests passed.\n";
} finally { if ($admin instanceof PDO) $admin->exec('DROP DATABASE IF EXISTS '.PUBLIC_SUBMIT_HTTP_TEST_DATABASE); }
