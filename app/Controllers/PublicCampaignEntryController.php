<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Core\SmartLinkService;

final class PublicCampaignEntryController
{
    public function __construct(
        private readonly Config $config,
        private readonly SmartLinkService $smartLinks,
    ) {
    }

    public function entry(string $alias): Response
    {
        try {
            $this->smartLinks->resolve($alias);

            return $this->render(true, 200);
        } catch (\Throwable) {
            return $this->render(false, 404);
        }
    }

    private function render(bool $isAvailable, int $status): Response
    {
        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/public-campaign-entry.php';

        return new Response(
            (string) ob_get_clean(),
            $status,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
            ],
        );
    }
}
