<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminQuizRepository;
use App\Core\AdminSession;
use App\Core\Config;
use App\Core\QuestionImageStorage;
use App\Core\QuizAuthoringService;
use App\Core\QuizDefinition;
use App\Core\QuizVersion;
use App\Core\QuizVersionRepository;
use App\Core\QuizVersionService;
use App\Core\Request;
use App\Core\Response;

final class AdminQuizController
{
    public function __construct(
        private readonly Config $config,
        private readonly AdminSession $sessions,
        private readonly AdminQuizRepository $quizzes,
        private readonly QuizVersionRepository $versions,
        private readonly QuizVersionService $versionService,
        private readonly QuizAuthoringService $authoring,
        private readonly QuestionImageStorage $images,
    ) {
    }

    public function index(Request $request): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }

        return $this->renderList($this->quizzes->listQuizzesForSchool($identity['school_id']));
    }

    public function editor(Request $request, string $quizId, string $versionId): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }

        $context = $this->versionContext($identity['school_id'], $quizId, $versionId);
        if ($context === null) {
            return $this->notFound();
        }

        return $this->renderEditor($context['quiz'], $context['version'], null, 200);
    }

    public function createDraft(Request $request, string $quizId): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return new Response('Permintaan tidak dapat diproses.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $quiz = $this->quizzes->findQuizForSchool($identity['school_id'], $this->positiveRouteId($quizId));
        if ($quiz === null) {
            return $this->notFound();
        }

        try {
            $draft = $this->versionService->getOrCreateDraft(
                $quiz['id'],
                $this->initialDefinition($quiz['name'], $this->quizzes->activeProgramCodesForSchool($identity['school_id'])),
            );
        } catch (\Throwable) {
            return $this->renderList(
                $this->quizzes->listQuizzesForSchool($identity['school_id']),
                'Draft baru tidak dapat dibuat. Pastikan sekolah memiliki program aktif.',
                422,
            );
        }

        return $this->redirect('/admin/quizzes/' . $quiz['id'] . '/versions/' . $draft->id . '/edit');
    }

    public function cloneVersion(Request $request, string $quizId, string $versionId): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return new Response('Permintaan tidak dapat diproses.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $context = $this->versionContext($identity['school_id'], $quizId, $versionId);
        if ($context === null) {
            return $this->notFound();
        }

        try {
            $draft = $this->versionService->cloneVersionToDraft($context['quiz']['id'], $context['version']->id);
        } catch (\Throwable) {
            return $this->renderList($this->quizzes->listQuizzesForSchool($identity['school_id']), 'Versi tidak dapat dikloning.', 422);
        }

        return $this->redirect('/admin/quizzes/' . $context['quiz']['id'] . '/versions/' . $draft->id . '/edit');
    }


    public function cloneVersionWithCurrentPresentations(
        Request $request,
        string $quizId,
        string $versionId,
    ): Response {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }

        if (!$this->validCsrf($request)) {
            return new Response(
                'Permintaan tidak dapat diproses.',
                403,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        $context = $this->versionContext(
            $identity['school_id'],
            $quizId,
            $versionId,
        );

        if ($context === null) {
            return $this->notFound();
        }

        try {
            $draft = $this->versionService
                ->cloneVersionToDraftWithCurrentPresentations(
                    $context['quiz']['id'],
                    $context['version']->id,
                );
        } catch (\Throwable) {
            return $this->renderList(
                $this->quizzes->listQuizzesForSchool(
                    $identity['school_id'],
                ),
                'Draft dengan data program terbaru tidak dapat dibuat.',
                422,
            );
        }

        return $this->redirect(
            '/admin/quizzes/'
            . $context['quiz']['id']
            . '/versions/'
            . $draft->id
            . '/edit'
        );
    }

    public function publish(Request $request, string $quizId, string $versionId): Response
    {
        return $this->transition($request, $quizId, $versionId, 'publish');
    }

    public function discard(Request $request, string $quizId, string $versionId): Response
    {
        return $this->transition($request, $quizId, $versionId, 'discard');
    }

    public function save(Request $request, string $quizId, string $versionId): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return new Response('Permintaan tidak dapat diproses.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $context = $this->versionContext($identity['school_id'], $quizId, $versionId);
        if ($context === null) {
            return $this->notFound();
        }
        if (!$context['version']->isDraft() || $this->versions->hasPersistedUsage($context['version']->id)) {
            return $this->renderEditor($context['quiz'], $context['version'], 'Versi historis atau non-draft tidak dapat diubah.', 422);
        }

        $newFiles = [];
        try {
            $definition = $this->definitionFromRequest($request, $context['version'], $newFiles);
            $updated = $this->authoring->replaceDraftDefinition(
                $identity['school_id'],
                $context['quiz']['id'],
                $context['version']->id,
                $definition,
            );
        } catch (\Throwable) {
            foreach ($newFiles as $path) {
                $this->images->discardNewlyStored($path);
            }

            return $this->renderEditor($context['quiz'], $context['version'], 'Draft tidak dapat disimpan. Periksa pertanyaan, opsi, bobot, dan gambar.', 422);
        }

        return $this->renderEditor($context['quiz'], $updated, 'Draft berhasil disimpan.', 200);
    }

    /** @return array{quiz: array{id: int, name: string}, version: QuizVersion}|null */
    private function versionContext(int $schoolId, string $quizId, string $versionId): ?array
    {
        try {
            $quizIdentity = $this->positiveRouteId($quizId);
            $versionIdentity = $this->positiveRouteId($versionId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $quiz = $this->quizzes->findQuizForSchool($schoolId, $quizIdentity);
        if ($quiz === null || !$this->quizzes->versionBelongsToSchool($schoolId, $quizIdentity, $versionIdentity)) {
            return null;
        }

        $version = $this->versions->findById($versionIdentity);
        if ($version === null || $version->quizId !== $quizIdentity) {
            return null;
        }

        return ['quiz' => $quiz, 'version' => $version];
    }

    private function transition(Request $request, string $quizId, string $versionId, string $operation): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return new Response('Permintaan tidak dapat diproses.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $context = $this->versionContext($identity['school_id'], $quizId, $versionId);
        if ($context === null) {
            return $this->notFound();
        }

        try {
            if ($operation === 'publish') {
                $this->versionService->publish($context['quiz']['id'], $context['version']->id);
            } else {
                $this->versionService->discard($context['quiz']['id'], $context['version']->id);
            }
        } catch (\Throwable) {
            return $this->renderList($this->quizzes->listQuizzesForSchool($identity['school_id']), 'Status versi tidak dapat diubah.', 422);
        }

        return $this->redirect('/admin/quizzes');
    }

    /** @param list<string> $newFiles */
    private function definitionFromRequest(Request $request, QuizVersion $version, array &$newFiles): QuizDefinition
    {
        $form = $request->formValues();
        $name = $this->stringField($form, 'name');
        $questionsInput = $form['questions'] ?? null;
        if (!is_array($questionsInput)) {
            throw new \InvalidArgumentException('Questions are required.');
        }

        $existingImages = [];
        foreach ($version->definition()->questions as $question) {
            $existingImages[$question['id']] = $question['image_path'] ?? null;
        }

        $questions = [];
        foreach (array_values($questionsInput) as $questionIndex => $input) {
            if (!is_array($input) || $this->checked($input, 'delete')) {
                continue;
            }

            $sourceId = $this->stringField($input, 'source_id');
            $imagePath = is_string($existingImages[$sourceId] ?? null) ? $existingImages[$sourceId] : null;
            $upload = $request->upload('question_image_' . $questionIndex);
            if ($upload !== null && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($upload['error'] !== UPLOAD_ERR_OK) {
                    throw new \InvalidArgumentException('Question image upload failed.');
                }

                $imagePath = $this->images->store($upload['tmp_name']);
                $newFiles[] = $imagePath;
            } elseif ($this->checked($input, 'remove_image')) {
                $imagePath = null;
            }

            $optionsInput = $input['options'] ?? [];
            if (!is_array($optionsInput)) {
                throw new \InvalidArgumentException('Question options are invalid.');
            }

            $options = [];
            foreach (array_values($optionsInput) as $optionIndex => $optionInput) {
                if (!is_array($optionInput) || $this->checked($optionInput, 'delete')) {
                    continue;
                }

                $text = $this->stringField($optionInput, 'text');
                if (trim($text) === '' && $this->stringField($optionInput, 'weights') === '') {
                    continue;
                }

                $options[] = [
                    'id' => 'option-' . $questionIndex . '-' . $optionIndex,
                    'text' => $text,
                    'order' => $this->orderField($optionInput, 'order'),
                    'weights' => $this->weights($this->stringField($optionInput, 'weights'), $version->definition()->programs),
                ];
            }

            $text = $this->stringField($input, 'text');
            if (trim($text) === '' && $sourceId === '' && $options === [] && $upload === null) {
                continue;
            }

            $questions[] = [
                'id' => 'question-' . $questionIndex,
                'text' => $text,
                'help_text' => $this->nullableStringField($input, 'help_text'),
                'image_path' => $imagePath,
                'order' => $this->orderField($input, 'order'),
                'options' => $options,
            ];
        }

        return new QuizDefinition($name, $version->versionNumber, $version->definition()->programs, $questions);
    }

    /** @param array<string, bool> $programs @return list<array{program: string, weight: float}> */
    private function weights(string $input, array $programs): array
    {
        $assignments = [];
        foreach (preg_split('/[\r\n,]+/', $input) ?: [] as $assignment) {
            $assignment = trim($assignment);
            if ($assignment === '') {
                continue;
            }

            [$program, $weight] = array_pad(explode('=', $assignment, 2), 2, null);
            if (!is_string($program) || !is_string($weight) || trim($program) === '' || !array_key_exists(trim($program), $programs) || !is_numeric(trim($weight))) {
                throw new \InvalidArgumentException('Weight assignment is invalid.');
            }

            $assignments[] = ['program' => trim($program), 'weight' => (float) trim($weight)];
        }

        return $assignments;
    }

    /** @param array<string, mixed> $values */
    private function orderField(array $values, string $key): int
    {
        $value = $this->stringField($values, $key);
        if (preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $value) !== 1 || (int) $value > 4294967295) {
            throw new \InvalidArgumentException('Order is invalid.');
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $values */
    private function checked(array $values, string $key): bool
    {
        return ($values[$key] ?? null) === '1';
    }

    /** @param array<string, mixed> $values */
    private function stringField(array $values, string $key): string
    {
        return isset($values[$key]) && is_string($values[$key]) ? $values[$key] : '';
    }

    /** @param array<string, mixed> $values */
    private function nullableStringField(array $values, string $key): ?string
    {
        $value = $this->stringField($values, $key);

        return trim($value) === '' ? null : $value;
    }

    /** @return array{admin_id: int, school_id: int}|null */
    private function identity(Request $request): ?array
    {
        $this->sessions->start($request->isHttps);

        return $this->sessions->identity();
    }

    private function validCsrf(Request $request): bool
    {
        return $this->sessions->verifyCsrf($this->stringField($request->formValues(), 'csrf_token'));
    }

    private function positiveRouteId(string $value): int
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Route identity is invalid.');
        }

        return (int) $value;
    }

    /** @param array<string, bool> $programs */
    private function initialDefinition(string $quizName, array $programs): QuizDefinition
    {
        if ($programs === []) {
            throw new \InvalidArgumentException('At least one active program is required.');
        }

        return new QuizDefinition(
            'Draft ' . $quizName,
            1,
            $programs,
            [[
                'id' => 'question-initial',
                'text' => 'Pertanyaan baru',
                'help_text' => null,
                'image_path' => null,
                'order' => 10,
                'options' => [
                    ['id' => 'option-initial-a', 'text' => 'Pilihan A', 'order' => 10, 'weights' => []],
                    ['id' => 'option-initial-b', 'text' => 'Pilihan B', 'order' => 20, 'weights' => []],
                ],
            ]],
        );
    }

    /** @param list<array{id: int, name: string, versions: list<array{id: int, version_number: int, name: string, status: string, is_used: bool}>}> $quizzes */
    private function renderList(array $quizzes, ?string $message = null, int $status = 200): Response
    {
        $appName = $this->escape($this->config->string('APP_NAME'));
        $csrfToken = $this->escape($this->sessions->csrfToken());
        $message = $message === null ? null : $this->escape($message);

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/admin-quizzes.php';

        return new Response((string) ob_get_clean(), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array{id: int, name: string} $quiz */
    private function renderEditor(array $quiz, QuizVersion $version, ?string $message, int $status): Response
    {
        $appName = $this->escape($this->config->string('APP_NAME'));
        $csrfToken = $this->escape($this->sessions->csrfToken());
        $message = $message === null ? null : $this->escape($message);
        $isEditable = $version->isDraft() && !$this->versions->hasPersistedUsage($version->id);

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/admin-quiz-editor.php';

        return new Response((string) ob_get_clean(), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function loginRequired(): Response
    {
        return $this->redirect('/admin/login');
    }

    private function notFound(): Response
    {
        return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function redirect(string $location): Response
    {
        return new Response('', 302, ['Location' => $location]);
    }
}
