<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\ParticipantResultService;
use App\Core\Request;
use App\Core\Response;
use App\Core\ResultPresentationFixtureProvider;
use App\Core\VisitorIdentityCookie;

final class ParticipantResultController
{
    public function __construct(
        private readonly Config $config,
        private readonly ResultPresentationFixtureProvider $fixtures,
        private readonly ?ParticipantResultService $results = null,
        private readonly ?VisitorIdentityCookie $visitorCookie = null,
    ) {
    }

    public function preview(string $name): Response
    {
        return $this->render($this->fixtures->preview($name));
    }

    public function show(Request $request, string $attemptUuid): Response
    {
        $visitorUuid = $request->cookie(VisitorIdentityCookie::NAME);
        if ($this->results === null || $this->visitorCookie === null
            || !$this->visitorCookie->isValidUuidV4($attemptUuid)
            || !$this->visitorCookie->isValidUuidV4($visitorUuid)) {
            return $this->notFound();
        }

        try {
            return $this->render($this->results->read($attemptUuid, $visitorUuid));
        } catch (\Throwable) {
            return $this->notFound();
        }
    }

    /** @param array<string, mixed> $presentation */
    private function render(array $presentation): Response
    {
        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $presentationJson = json_encode(
            $presentation,
            JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/participant-result.php';

        return new Response((string) ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function notFound(): Response
    {
        return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
