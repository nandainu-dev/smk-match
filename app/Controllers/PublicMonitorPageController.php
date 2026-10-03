<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\MonitorIdentityRepository;
use App\Core\ProgramMediaStorage;
use App\Core\Response;
use App\Core\SmartLinkRepository;
use App\Core\SmartLinkService;

final class PublicMonitorPageController
{
    public function __construct(
        private readonly Config $config,
        private readonly SmartLinkService $smartLinks,
        private readonly SmartLinkRepository $smartLinkRepository,
        private readonly MonitorIdentityRepository $monitorIdentity,
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
        $smartLink = $this->smartLinkRepository->findByAlias($resolution->alias);
        if ($smartLink === null) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $smartQrAvailable = $smartLink?->qrTargetUrl !== null;
        $smartQrTarget = htmlspecialchars(
            $smartLink?->qrTargetUrl ?? '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $identity = $this->monitorIdentity->forSchool($smartLink->schoolId);
        $footerLogoPath = is_string($identity['footer_logo_path']) && ProgramMediaStorage::isCanonicalPublicPath($identity['footer_logo_path'])
            ? htmlspecialchars($identity['footer_logo_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : null;
        $footerText = is_string($identity['footer_text']) && trim($identity['footer_text']) !== ''
            ? htmlspecialchars($identity['footer_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : null;

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/public-monitor.php';

        return new Response((string) ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
