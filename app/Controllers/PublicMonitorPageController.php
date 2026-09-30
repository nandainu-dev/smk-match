<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Core\SmartLinkService;

final class PublicMonitorPageController
{
    public function __construct(
        private readonly Config $config,
        private readonly SmartLinkService $smartLinks,
    ) {
    }

    public function show(string $alias): Response
    {
        try {
            $resolution = $this->smartLinks->resolve($alias);
        } catch (\Throwable) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $monitorAlias = htmlspecialchars($resolution->alias, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $smartQrTarget = htmlspecialchars(
            rtrim($this->config->string('APP_URL'), '/') . '/go/' . rawurlencode($resolution->alias),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/public-monitor.php';

        return new Response((string) ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
