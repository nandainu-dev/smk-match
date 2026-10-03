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
$threeProgramConfiguration = file_get_contents(SMK_MATCH_ROOT . '/app/Core/ThreeProgramQuizConfiguration.php');
$participantScript = file_get_contents(SMK_MATCH_ROOT . '/public/assets/js/participant-quiz.js');
$participantStyles = file_get_contents(SMK_MATCH_ROOT . '/public/assets/css/participant-quiz.css');
$authoringScript = file_get_contents(SMK_MATCH_ROOT . '/public/assets/js/admin-quiz-authoring.js');

foreach ([$routes, $controller, $listView, $editorView, $threeProgramConfiguration, $participantScript, $participantStyles] as $source) {
    adminQuizHttpAssert(is_string($source), 'An R2B source file could not be read.');
}
adminQuizHttpAssert(is_string($authoringScript), 'Authoring interaction source could not be read.');

adminQuizHttpAssert(str_contains($routes, "'/admin/quizzes'"), 'The authenticated quiz list route is missing.');
adminQuizHttpAssert(str_contains($routes, "'/admin/quizzes/{quizId}/versions/{versionId}/edit'"), 'The draft editor route is missing.');
adminQuizHttpAssert(str_contains($routes, "'/admin/quizzes/{quizId}/versions/{versionId}/save'"), 'The draft save route is missing.');
adminQuizHttpAssert(str_contains($routes, "'/admin/quizzes/{quizId}/versions/{versionId}/publish'") && str_contains($routes, 'postPattern'), 'The protected quiz publish POST route is missing.');
adminQuizHttpAssert(str_contains($controller, 'AdminSession') && str_contains($controller, 'versionContext'), 'Admin session/school scope guard is missing.');
adminQuizHttpAssert(str_contains($controller, 'validCsrf') && str_contains($controller, 'verifyCsrf'), 'Write CSRF guard is missing.');
adminQuizHttpAssert(str_contains($controller, "transition(\$request, \$quizId, \$versionId, 'publish')") && str_contains($controller, 'versionService->publish'), 'Publish does not delegate through the guarded QuizVersionService workflow.');
adminQuizHttpAssert(str_contains($controller, 'replaceDraftDefinition'), 'Draft writes do not use QuizAuthoringService.');
adminQuizHttpAssert(!str_contains($controller, '->prepare(') && !str_contains($controller, '->exec('), 'Controller contains direct SQL mutation.');
adminQuizHttpAssert(str_contains($controller, 'QuestionImageStorage') && str_contains($controller, 'discardNewlyStored'), 'Question image storage/failed-write cleanup path is missing.');
adminQuizHttpAssert(str_contains($editorView, 'multipart/form-data'), 'Editor is not multipart for image upload.');
adminQuizHttpAssert(str_contains($editorView, 'question_image_') && str_contains($editorView, 'remove_image'), 'Editor cannot upload or remove question image references.');
adminQuizHttpAssert(
    str_contains($editorView, 'primary_program')
        && str_contains($editorView, 'Pilihan <?= $escape($optionLetter) ?>')
        && str_contains($editorView, '<span>Jurusan</span>')
        && str_contains($editorView, '$programCodes'),
    'Each existing option does not expose its own configured-program selector.',
);
adminQuizHttpAssert(
    !str_contains($editorView, 'primary_points')
        && !str_contains($editorView, 'weights_by_program')
        && !str_contains($editorView, '<span>Poin</span>')
        && !str_contains($authoringScript, 'primary_points')
        && !str_contains($authoringScript, 'weights_by_program')
        && !str_contains($authoringScript, 'textContent = "Poin"'),
    'The simple authoring UI still exposes a manual point or advanced-weight input.',
);
adminQuizHttpAssert(str_contains($listView, 'program_ids[]') && str_contains($listView, 'Pilih tepat 3 program aktif'), 'New drafts do not require an explicit three-program selection.');
adminQuizHttpAssert(str_contains($controller, 'selectedProgramIds') && str_contains($controller, 'getOrCreateDraftForProgramIds') && str_contains($controller, 'ThreeProgramQuizConfiguration') && str_contains($threeProgramConfiguration, 'PROGRAM_COUNT = 3'), 'The stable-ID three-program product boundary is missing.');
adminQuizHttpAssert(!str_contains($editorView, 'KODE=angka'), 'Technical raw weight syntax remains exposed as the primary authoring UI.');
adminQuizHttpAssert(
    str_contains($controller, 'weightInput')
        && str_contains($controller, "stringField(\$values, 'weights')")
        && str_contains($controller, "return \$primaryProgram . '=1';"),
    'The simple selector does not retain the automatic one-point backend conversion.',
);
adminQuizHttpAssert(str_contains($controller, 'for ($questionNumber = 1; $questionNumber <= 5; $questionNumber++)') && str_contains($controller, "['A', 'B', 'C', 'D']"), 'New drafts do not initialize five A-D questions.');
adminQuizHttpAssert(
    str_contains($controller, '$programCodes[$optionIndex % count($programCodes)]')
        && !str_contains($controller, "'weights' => [['program' => \$defaultProgram"),
    'Initial A-D options still collapse onto the first configured program.',
);
adminQuizHttpAssert(str_contains($editorView, 'admin-add-question') && str_contains($editorView, 'admin-question-template'), 'The editor does not provide a functional add-question template.');
adminQuizHttpAssert(
    str_contains($authoringScript, 'for (let optionIndex = 0; optionIndex < 4;')
        && str_contains($authoringScript, 'add.addEventListener("click", addQuestion)')
        && str_contains($authoringScript, 'existingOptions')
        && str_contains($authoringScript, 'select.required = true')
        && str_contains($authoringScript, 'attachDelete(card)')
        && !str_contains($authoringScript, 'String.fromCharCode(65 + 4)'),
    'Existing and new authoring question cards do not share the canonical editable A-D option structure.',
);
adminQuizHttpAssert(str_contains($editorView, "confirm('Hapus pertanyaan ini dari draft?')") && !str_contains($editorView, '][delete]" value="1"> Hapus pertanyaan'), 'Draft question deletion is not a clear confirmed action.');
adminQuizHttpAssert(str_contains($listView, 'Edit draft') && str_contains($listView, 'Kloning ke draft'), 'Version-safe list actions are incomplete.');
adminQuizHttpAssert(
    str_contains($listView, "\$version['status'] === 'draft' && !\$version['is_used']")
        && str_contains($listView, '/publish')
        && str_contains($listView, 'PUBLISH')
        && str_contains($listView, 'Publikasikan quiz ini?')
        && str_contains($editorView, 'if ($isEditable)')
        && str_contains($editorView, '/publish')
        && str_contains($editorView, 'belum otomatis diaktifkan untuk Campaign'),
    'The draft-only publish action or its explicit non-activation confirmation is missing.',
);
adminQuizHttpAssert(str_contains($participantScript, 'validQuestionImagePath') && str_contains($participantScript, 'pq-question-image'), 'Participant image rendering is missing.');
adminQuizHttpAssert(str_contains($participantStyles, '.pq-question-image'), 'Participant image responsive styling is missing.');
adminQuizHttpAssert(!str_contains($controller, "'DKV' =>") && !str_contains($controller, "'MPLB' =>") && !str_contains($controller, "'PM' =>"), 'Controller hardcodes demo programs.');

echo "Admin quiz authoring HTTP/UI structure tests passed.\n";
