<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAccessService;
use App\Core\AdminSession;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class AdminAuthController
{
    private const INVALID_CREDENTIALS = 'Email atau kata sandi tidak valid.';
    private const INVALID_REQUEST = 'Permintaan tidak dapat diproses.';

    public function __construct(
        private readonly Config $config,
        private readonly AdminAccessService $access,
        private readonly AdminSession $sessions,
    ) {
    }

    public function loginForm(Request $request): Response
    {
        $this->sessions->start($request->isHttps);

        return $this->renderLogin(null, 200);
    }

    public function login(Request $request): Response
    {
        $this->sessions->start($request->isHttps);
        $form = $this->form($request);

        if (!$this->sessions->verifyCsrf($this->stringValue($form, 'csrf_token'))) {
            return $this->renderLogin(self::INVALID_REQUEST, 403);
        }

        $identity = $this->access->authenticate(
            $this->stringValue($form, 'email') ?? '',
            $this->stringValue($form, 'password') ?? '',
        );
        if ($identity === null) {
            return $this->renderLogin(self::INVALID_CREDENTIALS, 401);
        }

        $this->sessions->login($identity);

        return new Response('', 302, ['Location' => '/admin']);
    }

    public function logout(Request $request): Response
    {
        $this->sessions->start($request->isHttps);
        $form = $this->form($request);

        if (!$this->sessions->verifyCsrf($this->stringValue($form, 'csrf_token'))) {
            return new Response(self::INVALID_REQUEST, 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $this->sessions->logout();

        return new Response('', 302, ['Location' => '/admin/login']);
    }

    /** @return array<string, mixed> */
    private function form(Request $request): array
    {
        $form = [];
        parse_str($request->body, $form);

        return $form;
    }

    /** @param array<string, mixed> $form */
    private function stringValue(array $form, string $key): ?string
    {
        return isset($form[$key]) && is_string($form[$key]) ? $form[$key] : null;
    }

    private function renderLogin(?string $error, int $status): Response
    {
        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrfToken = htmlspecialchars($this->sessions->csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $errorMessage = $error === null
            ? null
            : htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/admin-login.php';

        return new Response((string) ob_get_clean(), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
