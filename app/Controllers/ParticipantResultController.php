<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Core\ResultPresentationFixtureProvider;

final class ParticipantResultController
{
    public function __construct(
        private readonly Config $config,
        private readonly ResultPresentationFixtureProvider $fixtures,
    ) {
    }

    public function preview(string $name): Response
    {
        $presentation = $this->fixtures->preview($name);
        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $presentationJson = json_encode(
            $presentation,
            JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/participant-result.php';

        return new Response((string) ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
