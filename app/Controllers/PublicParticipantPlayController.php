<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Core\SmartLinkService;

final class PublicParticipantPlayController
{
    public function __construct(
        private readonly Config $config,
        private readonly SmartLinkService $smartLinks,
    ) {
    }

    public function play(string $alias): Response
    {
        try {
            $resolution = $this->smartLinks->resolve($alias);
        } catch (\Throwable) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $quizJson = json_encode([
            'mode' => 'real',
            'alias' => $resolution->alias,
        ], JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR);

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/participant-quiz.php';

        return new Response((string) ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
