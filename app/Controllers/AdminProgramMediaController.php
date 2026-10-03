<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Config;
use App\Core\ProgramPresentationMediaService;
use App\Core\Request;
use App\Core\Response;

final class AdminProgramMediaController
{
    public function __construct(
        private readonly Config $config,
        private readonly AdminSession $sessions,
        private readonly ProgramPresentationMediaService $media,
    ) {
    }

    public function index(Request $request): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }

        return $this->render($this->media->listPrograms($identity['school_id']));
    }


    public function savePresentationContent(Request $request, string $programId): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return $this->invalidRequest();
        }

        $program = $this->programForSchool($identity['school_id'], $programId);
        if ($program === null) {
            return $this->notFound();
        }

        $form = $request->formValues();
        $skills = isset($form['skills']) && is_string($form['skills']) ? $form['skills'] : '';
        $careers = isset($form['careers']) && is_string($form['careers']) ? $form['careers'] : '';

        try {
            $this->media->savePresentationContent(
                $identity['school_id'],
                (int) $program['id'],
                $skills,
                $careers,
            );
        } catch (\Throwable) {
            return $this->render(
                $this->media->listPrograms($identity['school_id']),
                'Skills atau peluang karir tidak dapat disimpan.',
                422,
            );
        }

        return $this->redirect('/admin/program-media');
    }

    public function upload(Request $request, string $programId): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return $this->invalidRequest();
        }

        $program = $this->programForSchool($identity['school_id'], $programId);
        if ($program === null) {
            return $this->notFound();
        }

        $upload = $request->upload('mascot');
        if ($upload === null || $upload['error'] !== UPLOAD_ERR_OK) {
            return $this->render($this->media->listPrograms($identity['school_id']), 'Unggahan maskot tidak valid.', 422);
        }

        try {
            $this->media->uploadMascot($identity['school_id'], $program['id'], $upload['tmp_name']);
        } catch (\Throwable) {
            return $this->render($this->media->listPrograms($identity['school_id']), 'Maskot tidak dapat disimpan.', 422);
        }

        return $this->redirect('/admin/program-media');
    }

    public function remove(Request $request, string $programId): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return $this->invalidRequest();
        }

        $program = $this->programForSchool($identity['school_id'], $programId);
        if ($program === null) {
            return $this->notFound();
        }

        try {
            $this->media->removeMascot($identity['school_id'], $program['id']);
        } catch (\Throwable) {
            return $this->render($this->media->listPrograms($identity['school_id']), 'Referensi maskot tidak dapat dihapus.', 422);
        }

        return $this->redirect('/admin/program-media');
    }

    /** @return array{admin_id: int, school_id: int}|null */
    private function identity(Request $request): ?array
    {
        $this->sessions->start($request->isHttps);

        return $this->sessions->identity();
    }

    /** @return array{id: int, name: string, code: string, mascot_path: ?string}|null */
    private function programForSchool(int $schoolId, string $programId): ?array
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $programId) !== 1) {
            return null;
        }

        return $this->media->program($schoolId, (int) $programId);
    }

    private function validCsrf(Request $request): bool
    {
        $form = $request->formValues();

        return $this->sessions->verifyCsrf(isset($form['csrf_token']) && is_string($form['csrf_token']) ? $form['csrf_token'] : null);
    }

    /** @param list<array{id: int, name: string, code: string, mascot_path: ?string}> $programs */
    private function render(array $programs, ?string $message = null, int $status = 200): Response
    {
        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrfToken = htmlspecialchars($this->sessions->csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = $message === null ? null : htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/admin-program-media.php';

        return new Response((string) ob_get_clean(), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function invalidRequest(): Response
    {
        return new Response('Permintaan tidak dapat diproses.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
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
