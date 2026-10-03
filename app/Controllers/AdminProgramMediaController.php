<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Config;
use App\Core\MonitorIdentityService;
use App\Core\ProgramPresentationMediaService;
use App\Core\Request;
use App\Core\Response;

final class AdminProgramMediaController
{
    public function __construct(
        private readonly Config $config,
        private readonly AdminSession $sessions,
        private readonly ProgramPresentationMediaService $media,
        private readonly ?MonitorIdentityService $monitorIdentity = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }

        return $this->render($this->media->listPrograms($identity['school_id']), null, 200, $this->monitorIdentityForSchool($identity['school_id']));
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

    public function uploadRole(Request $request, string $programId, string $role): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return $this->invalidRequest();
        }
        if (!in_array($role, ['result', 'share', 'monitor'], true)) {
            return $this->notFound();
        }

        $program = $this->programForSchool($identity['school_id'], $programId);
        if ($program === null) {
            return $this->notFound();
        }
        $upload = $request->upload('media');
        if ($upload === null || $upload['error'] !== UPLOAD_ERR_OK) {
            return $this->render($this->media->listPrograms($identity['school_id']), $this->uploadFailureMessage($upload), 422);
        }

        try {
            $this->media->uploadMedia($identity['school_id'], (int) $program['id'], $role, $upload['tmp_name']);
        } catch (\InvalidArgumentException $exception) {
            return $this->render($this->media->listPrograms($identity['school_id']), $this->imageValidationMessage($exception), 422);
        } catch (\Throwable) {
            return $this->render($this->media->listPrograms($identity['school_id']), 'Gambar program tidak dapat disimpan.', 422);
        }

        return $this->redirect('/admin/program-media');
    }

    public function removeRole(Request $request, string $programId, string $role): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return $this->invalidRequest();
        }
        if (!in_array($role, ['result', 'share', 'monitor'], true)) {
            return $this->notFound();
        }

        $program = $this->programForSchool($identity['school_id'], $programId);
        if ($program === null) {
            return $this->notFound();
        }
        try {
            $this->media->removeMedia($identity['school_id'], (int) $program['id'], $role);
        } catch (\Throwable) {
            return $this->render($this->media->listPrograms($identity['school_id']), 'Referensi gambar program tidak dapat dihapus.', 422);
        }

        return $this->redirect('/admin/program-media');
    }

    public function saveMonitorIdentity(Request $request): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return $this->invalidRequest();
        }

        $form = $request->formValues();
        $footerText = isset($form['footer_text']) && is_string($form['footer_text']) ? $form['footer_text'] : null;
        $upload = $request->upload('footer_logo');
        if ($upload !== null && $upload['error'] === UPLOAD_ERR_NO_FILE) {
            $upload = null;
        }
        if ($upload !== null && $upload['error'] !== UPLOAD_ERR_OK) {
            return $this->render($this->media->listPrograms($identity['school_id']), 'Unggahan logo footer tidak valid.', 422, $this->monitorIdentityForSchool($identity['school_id']));
        }

        try {
            $this->monitorIdentityService()->save($identity['school_id'], $upload['tmp_name'] ?? null, $footerText);
        } catch (\Throwable) {
            return $this->render($this->media->listPrograms($identity['school_id']), 'Identitas monitor tidak dapat disimpan.', 422, $this->monitorIdentityForSchool($identity['school_id']));
        }

        return $this->redirect('/admin/program-media');
    }

    public function rename(Request $request, string $programId): Response
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
        $name = isset($form['name']) && is_string($form['name']) ? $form['name'] : '';
        $code = isset($form['code']) && is_string($form['code']) ? $form['code'] : '';

        try {
            $this->media->renameProgram($identity['school_id'], $program['id'], $name, $code);
        } catch (\Throwable) {
            return $this->render($this->media->listPrograms($identity['school_id']), 'Nama atau kode program tidak dapat disimpan.', 422);
        }

        return $this->redirect('/admin/program-media');
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
            $this->media->savePresentationContent($identity['school_id'], (int) $program['id'], $skills, $careers);
        } catch (\Throwable) {
            return $this->render($this->media->listPrograms($identity['school_id']), 'Skills atau peluang karir tidak dapat disimpan.', 422);
        }

        return $this->redirect('/admin/program-media');
    }

    /** @return array{admin_id: int, school_id: int}|null */
    private function identity(Request $request): ?array
    {
        $this->sessions->start($request->isHttps);

        return $this->sessions->identity();
    }

    /** @return array<string, mixed>|null */
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

    /** @param array{name: string, tmp_name: string, error: int, size: int}|null $upload */
    private function uploadFailureMessage(?array $upload): string
    {
        return match ($upload['error'] ?? null) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran gambar melebihi batas unggahan server.',
            UPLOAD_ERR_PARTIAL => 'Unggahan gambar tidak lengkap. Coba lagi.',
            UPLOAD_ERR_NO_FILE, null => 'Pilih file gambar untuk diunggah.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Unggahan gambar gagal diproses oleh server.',
            default => 'Unggahan gambar tidak valid.',
        };
    }

    private function imageValidationMessage(\InvalidArgumentException $exception): string
    {
        return match ($exception->getMessage()) {
            'Program media exceeds the allowed size.' => 'Ukuran gambar maksimal 5 MiB.',
            'Program media type or dimensions are invalid.' => 'Format atau dimensi gambar tidak didukung.',
            default => 'File gambar tidak valid.',
        };
    }

    /** @return array{footer_logo_path: ?string, footer_text: ?string} */
    private function monitorIdentityForSchool(int $schoolId): array
    {
        return $this->monitorIdentity?->forSchool($schoolId) ?? ['footer_logo_path' => null, 'footer_text' => null];
    }

    private function monitorIdentityService(): MonitorIdentityService
    {
        if ($this->monitorIdentity === null) {
            throw new \LogicException('Monitor identity service is not configured.');
        }

        return $this->monitorIdentity;
    }

    /** @param list<array<string, mixed>> $programs @param array{footer_logo_path: ?string, footer_text: ?string}|null $monitorIdentity */
    private function render(array $programs, ?string $message = null, int $status = 200, ?array $monitorIdentity = null): Response
    {
        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrfToken = htmlspecialchars($this->sessions->csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = $message === null ? null : htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $monitorIdentity ??= ['footer_logo_path' => null, 'footer_text' => null];

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
