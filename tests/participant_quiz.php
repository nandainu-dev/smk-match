<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Request;

function expectParticipantQuiz(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = Config::load(SMK_MATCH_ROOT);
$routes = require SMK_MATCH_ROOT . '/routes/web.php';
$router = $routes($config);
$participantPage = $router->dispatch(new Request('GET', '/'));
$participantScript = (string) file_get_contents(SMK_MATCH_ROOT . '/public/assets/js/participant-quiz.js');

expectParticipantQuiz($participantPage->status === 200, 'Participant route did not return HTTP 200.');
expectParticipantQuiz(str_contains($participantPage->body, 'id="participant-quiz"'), 'Participant quiz root is missing.');
expectParticipantQuiz(str_contains($participantPage->body, '/assets/css/participant-quiz.css'), 'Participant stylesheet is missing.');
expectParticipantQuiz(str_contains($participantPage->body, '/assets/js/participant-quiz.js'), 'Participant JavaScript is missing.');
expectParticipantQuiz(str_contains($participantPage->body, 'id="participant-quiz-data"'), 'Sanitized quiz payload is missing.');

$payloadMatch = [];
expectParticipantQuiz(
    preg_match('/<script id="participant-quiz-data" type="application\/json">(.*?)<\/script>/s', $participantPage->body, $payloadMatch) === 1,
    'Sanitized quiz payload could not be read.',
);
$payload = json_decode($payloadMatch[1], true, 512, JSON_THROW_ON_ERROR);
expectParticipantQuiz(count($payload['questions']) === 5, 'The current development fixture must expose five questions.');
expectParticipantQuiz(array_keys($payload) === ['name', 'questions'], 'Payload contains unexpected quiz fields.');

foreach ($payload['questions'] as $question) {
    expectParticipantQuiz(array_keys($question) === ['id', 'text', 'order', 'options'], 'Question payload contains unexpected fields.');
    foreach ($question['options'] as $option) {
        expectParticipantQuiz(array_keys($option) === ['id', 'text', 'order'], 'Option payload contains unexpected fields.');
    }
}

$serializedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
foreach (['weights', 'programs', 'DKV', 'MPLB', 'PM'] as $forbiddenPayloadValue) {
    expectParticipantQuiz(!str_contains($serializedPayload, $forbiddenPayloadValue), "Forbidden participant payload value: {$forbiddenPayloadValue}");
}

foreach (["name: 'fullName'", "name: 'originSchool'", "name: 'className'", "name: 'whatsapp'", "checkbox.name = 'marketingConsent'" ] as $requiredControl) {
    expectParticipantQuiz(str_contains($participantScript, $requiredControl), "Required identity control is missing: {$requiredControl}");
}
expectParticipantQuiz(!str_contains($participantScript, 'checkbox.required'), 'Marketing consent must remain optional.');
expectParticipantQuiz(str_contains($participantScript, 'questions.length'), 'Question count must be derived dynamically.');
expectParticipantQuiz(
    str_contains($participantScript, "progress.setAttribute('role', 'progressbar')"),
    'Semantic progress UI is missing.',
);
expectParticipantQuiz(
    str_contains($participantScript, "progress.setAttribute('aria-valuemax', String(questions.length))")
        && str_contains($participantScript, "progress.setAttribute('aria-valuenow', String(state.currentIndex + 1))"),
    'Progress values must derive from the dynamic question count and current index.',
);
expectParticipantQuiz(str_contains($participantScript, "element('fieldset', 'pq-options')"), 'Semantic option fieldset is missing.');
expectParticipantQuiz(str_contains($participantScript, "input.type = 'radio'"), 'Semantic radio option controls are missing.');
expectParticipantQuiz(str_contains($participantScript, 'next.disabled = !state.answers[question.id]'), 'Unanswered next protection is missing.');
expectParticipantQuiz(str_contains($participantScript, "setState('INTRO')"), 'Back navigation to intro is missing.');
expectParticipantQuiz(str_contains($participantScript, "setState('ANALYZING')"), 'Analyzing state is missing.');
expectParticipantQuiz(str_contains($participantScript, "RESULT_HANDOFF"), 'Result handoff state is missing.');
expectParticipantQuiz(str_contains($participantScript, '/assets/mascots/mascot-all.png'), 'Global mascot asset is missing from landing.');

foreach (['/assets/mascots/dkv/', '/assets/mascots/mplb/', '/assets/mascots/pm/', 'dkv_score', 'mplb_score', 'pm_score', 'dominant_program', 'result-detail', 'share-result'] as $forbiddenPageValue) {
    expectParticipantQuiz(!str_contains($participantPage->body . $participantScript, $forbiddenPageValue), "Forbidden G8 participant page value: {$forbiddenPageValue}");
}

$health = $router->dispatch(new Request('GET', '/health'));
expectParticipantQuiz($health->status === 200, 'Health route no longer returns HTTP 200.');

echo "Participant quiz tests passed.\n";
