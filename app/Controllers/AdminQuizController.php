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
use App\Core\ThreeProgramQuizConfiguration;

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
        private readonly ThreeProgramQuizConfiguration $threePrograms,
    ) {
    }

    public function index(Request $request): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }

        return $this->renderList(
            $this->quizzes->listQuizzesForSchool($identity['school_id']),
            $this->quizzes->activeProgramsForSchool($identity['school_id']),
        );
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

        try {
            $this->threePrograms->assertVersion($context['version']);
        } catch (\Throwable) {
            return $this->renderEditor(
                $context['quiz'],
                $context['version'],
                'Versi ini tidak memakai tepat tiga program dan tidak dapat diedit melalui konfigurasi produk.',
                422,
            );
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
            $selectedProgramIds = $this->selectedProgramIds($request);
            $programs = $this->threePrograms->selectedProgramCodes(
                $this->quizzes->activeProgramsForSchool($identity['school_id']),
                $selectedProgramIds,
            );
            $draft = $this->versionService->getOrCreateDraftForProgramIds(
                $quiz['id'],
                $this->initialDefinition($quiz['name'], $programs),
                $selectedProgramIds,
            );
            $this->threePrograms->assertVersion($draft);
        } catch (\Throwable) {
            return $this->renderList(
                $this->quizzes->listQuizzesForSchool($identity['school_id']),
                $this->quizzes->activeProgramsForSchool($identity['school_id']),
                'Draft baru tidak dapat dibuat. Pilih tepat tiga program aktif milik sekolah.',
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
            $this->threePrograms->assertVersion($context['version']);
            $draft = $this->versionService->cloneVersionToDraft($context['quiz']['id'], $context['version']->id);
        } catch (\Throwable) {
            return $this->renderList(
                $this->quizzes->listQuizzesForSchool($identity['school_id']),
                $this->quizzes->activeProgramsForSchool($identity['school_id']),
                'Versi tidak dapat dikloning. Konfigurasi produk memerlukan tepat tiga program.',
                422,
            );
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
            return new Response('Permintaan tidak dapat diproses.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $context = $this->versionContext($identity['school_id'], $quizId, $versionId);
        if ($context === null) {
            return $this->notFound();
        }

        try {
            $this->threePrograms->assertVersion($context['version']);
            $draft = $this->versionService->cloneVersionToDraftWithCurrentPresentations(
                $context['quiz']['id'],
                $context['version']->id,
            );
        } catch (\Throwable) {
            return $this->renderList(
                $this->quizzes->listQuizzesForSchool($identity['school_id']),
                $this->quizzes->activeProgramsForSchool($identity['school_id']),
                'Draft dengan data program terbaru tidak dapat dibuat.',
                422,
            );
        }

        return $this->redirect('/admin/quizzes/' . $context['quiz']['id'] . '/versions/' . $draft->id . '/edit');
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
        try {
            $this->threePrograms->assertVersion($context['version']);
        } catch (\Throwable) {
            return $this->renderEditor($context['quiz'], $context['version'], 'Draft ini tidak memakai tepat tiga program dan tidak dapat diubah melalui konfigurasi produk.', 422);
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
        } catch (\InvalidArgumentException) {
            foreach ($newFiles as $path) {
                $this->images->discardNewlyStored($path);
            }

            return $this->renderEditor($context['quiz'], $context['version'], 'Draft tidak dapat disimpan. Lengkapi teks dan pilih jurusan untuk setiap Pilihan A–D.', 422);
        } catch (\Throwable) {
            foreach ($newFiles as $path) {
                $this->images->discardNewlyStored($path);
            }

            return $this->renderEditor($context['quiz'], $context['version'], 'Draft tidak dapat disimpan. Periksa pertanyaan dan gambar.', 422);
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
                $this->threePrograms->assertVersion($context['version']);
                $this->versionService->publish($context['quiz']['id'], $context['version']->id);
            } else {
                $this->versionService->discard($context['quiz']['id'], $context['version']->id);
            }
        } catch (\Throwable) {
            return $this->renderList(
                $this->quizzes->listQuizzesForSchool($identity['school_id']),
                $this->quizzes->activeProgramsForSchool($identity['school_id']),
                'Status versi tidak dapat diubah.',
                422,
            );
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
                $weightInput = $this->weightInput($optionInput, $version->definition()->programs);
                $weights = $this->weights($weightInput, $version->definition()->programs);
                if (trim($text) === '' || count($weights) !== 1 || $weights[0]['weight'] !== 1.0) {
                    throw new \InvalidArgumentException('Each option requires text and one program.');
                }

                $options[] = [
                    'id' => 'option-' . $questionIndex . '-' . $optionIndex,
                    'text' => $text,
                    'order' => $this->orderField($optionInput, 'order'),
                    'weights' => $weights,
                ];
            }

            if (count($options) !== 4) {
                throw new \InvalidArgumentException('Each question requires options A-D.');
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

    /**
     * The friendly form submits dynamic program fields; this converts them to
     * the locked KODE=angka representation consumed by the existing parser.
     * Legacy requests using the original weights textarea remain supported.
     *
     * @param array<string, mixed> $values
     * @param array<string, bool> $programs
     */
    private function weightInput(array $values, array $programs): string
    {
        $primaryProgram = $this->stringField($values, 'primary_program');
        if ($primaryProgram !== '') {
            if (!array_key_exists($primaryProgram, $programs)) {
                throw new \InvalidArgumentException('Primary weight program is invalid.');
            }

            return $primaryProgram . '=1';
        }

        if (!array_key_exists('weights_by_program', $values)) {
            return $this->stringField($values, 'weights');
        }

        $submitted = $values['weights_by_program'];
        if (!is_array($submitted)) {
            throw new \InvalidArgumentException('Weight assignments are invalid.');
        }

        $assignments = [];
        foreach ($programs as $code => $_present) {
            $value = $submitted[$code] ?? '';
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $assignments[] = $code . '=' . trim($value);
        }

        foreach ($submitted as $code => $_value) {
            if (!is_string($code) || !array_key_exists($code, $programs)) {
                throw new \InvalidArgumentException('Weight program is invalid.');
            }
        }

        return implode("\n", $assignments);
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

    /** @return list<int> */
    private function selectedProgramIds(Request $request): array
    {
        $selected = $request->formValues()['program_ids'] ?? null;
        if (!is_array($selected)) {
            throw new \InvalidArgumentException('Program selection is required.');
        }

        $programIds = [];
        foreach ($selected as $programId) {
            if (!is_string($programId) || preg_match('/\A[1-9][0-9]*\z/D', $programId) !== 1) {
                throw new \InvalidArgumentException('Program selection is invalid.');
            }
            $programIds[] = (int) $programId;
        }

        return $programIds;
    }

    /** @param array<string, bool> $programs */
    private function initialDefinition(string $quizName, array $programs): QuizDefinition
    {
        if ($programs === []) {
            throw new \InvalidArgumentException('At least one active program is required.');
        }

        $programCodes = array_keys($programs);

        $questions = [];
        for ($questionNumber = 1; $questionNumber <= 5; $questionNumber++) {
            $options = [];
            foreach (['A', 'B', 'C', 'D'] as $optionIndex => $label) {
                $options[] = [
                    'id' => 'option-initial-' . $questionNumber . '-' . strtolower($label),
                    'text' => 'Pilihan ' . $label,
                    'order' => ($optionIndex + 1) * 10,
                    'weights' => [[
                        'program' => $programCodes[$optionIndex % count($programCodes)],
                        'weight' => 1.0,
                    ]],
                ];
            }
            $questions[] = [
                'id' => 'question-initial-' . $questionNumber,
                'text' => 'Pertanyaan ' . $questionNumber,
                'help_text' => null,
                'image_path' => null,
                'order' => $questionNumber * 10,
                'options' => $options,
            ];
        }

        return new QuizDefinition(
            'Draft ' . $quizName,
            1,
            $programs,
            $questions,
        );
    }

    /**
     * @param list<array{id: int, name: string, versions: list<array{id: int, version_number: int, name: string, status: string, is_used: bool}>}> $quizzes
     * @param list<array{id: int, code: string, name: string}> $activePrograms
     */
    private function renderList(array $quizzes, array $activePrograms, ?string $message = null, int $status = 200): Response
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
