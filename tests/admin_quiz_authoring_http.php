<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

function adminQuizHttpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$routes = file_get_contents(SMK_MATCH_ROOT . '/routes/web.php');
$controller = file_get_contents(SMK_MATCH_ROOT . '/app/Controllers/AdminQuizController.php');
$listView = file_get_contents(SMK_MATCH_ROOT . '/resources/views/pages/admin-quizzes.php');
$editorView = file_get_contents(SMK_MATCH_ROOT . '/resources/views/pages/admin-quiz-editor.php');
$participantScript = file_get_contents(SMK_MATCH_ROOT . '/public/assets/js/participant-quiz.js');
$participantStyles = file_get_contents(SMK_MATCH_ROOT . '/public/assets/css/participant-quiz.css');

foreach ([$routes, $controller, $listView, $editorView, $participantScript, $participantStyles] as $source) {
    adminQuizHttpAssert(is_string($source), 'An R2B source file could not be read.');
}

adminQuizHttpAssert(str_contains($routes, "'/admin/quizzes'"), 'The authenticated quiz list route is missing.');
adminQuizHttpAssert(str_contains($routes, "'/admin/quizzes/{quizId}/versions/{versionId}/edit'"), 'The draft editor route is missing.');
adminQuizHttpAssert(str_contains($routes, "'/admin/quizzes/{quizId}/versions/{versionId}/save'"), 'The draft save route is missing.');
adminQuizHttpAssert(str_contains($controller, 'AdminSession') && str_contains($controller, 'versionContext'), 'Admin session/school scope guard is missing.');
adminQuizHttpAssert(str_contains($controller, 'validCsrf') && str_contains($controller, 'verifyCsrf'), 'Write CSRF guard is missing.');
adminQuizHttpAssert(str_contains($controller, 'replaceDraftDefinition'), 'Draft writes do not use QuizAuthoringService.');
adminQuizHttpAssert(!str_contains($controller, '->prepare(') && !str_contains($controller, '->exec('), 'Controller contains direct SQL mutation.');
adminQuizHttpAssert(str_contains($controller, 'QuestionImageStorage') && str_contains($controller, 'discardNewlyStored'), 'Question image storage/failed-write cleanup path is missing.');
adminQuizHttpAssert(str_contains($editorView, 'multipart/form-data'), 'Editor is not multipart for image upload.');
adminQuizHttpAssert(str_contains($editorView, 'question_image_') && str_contains($editorView, 'remove_image'), 'Editor cannot upload or remove question image references.');
adminQuizHttpAssert(str_contains($editorView, 'KODE=angka'), 'N-program weight input is not exposed.');
adminQuizHttpAssert(str_contains($listView, 'Edit draft') && str_contains($listView, 'Kloning ke draft'), 'Version-safe list actions are incomplete.');
adminQuizHttpAssert(str_contains($participantScript, 'validQuestionImagePath') && str_contains($participantScript, 'pq-question-image'), 'Participant image rendering is missing.');
adminQuizHttpAssert(str_contains($participantStyles, '.pq-question-image'), 'Participant image responsive styling is missing.');
adminQuizHttpAssert(!str_contains($controller, "'DKV' =>") && !str_contains($controller, "'MPLB' =>") && !str_contains($controller, "'PM' =>"), 'Controller hardcodes demo programs.');

echo "Admin quiz authoring HTTP/UI structure tests passed.\n";
