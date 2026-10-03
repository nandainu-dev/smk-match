<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MonitorService
{
    public function __construct(
        private readonly SmartLinkService $smartLinks,
        private readonly MonitorReadRepository $monitorReads,
        private readonly ?SmartLinkRepository $smartLinkRepository = null,
        private readonly ?MonitorIdentityRepository $monitorIdentity = null,
    ) {
    }

    public function read(
        string $alias,
        int $recentLimit = 20,
        ?int $reconciliationAfterResultId = null,
    ): MonitorSnapshot
    {
        $resolution = $this->smartLinks->resolve($alias);
        $readModel = $this->monitorReads->readActiveBatch(
            $resolution->campaignId,
            $recentLimit,
            $reconciliationAfterResultId,
        );

        if ($readModel === null) {
            throw new RuntimeException('Monitor campaign has no active batch.');
        }

        if ($readModel['batch']['campaign_id'] !== $resolution->campaignId) {
            throw new RuntimeException('Monitor batch does not belong to the resolved campaign.');
        }

        $footer = ['footer_logo_path' => null, 'footer_text' => null];
        if ($this->smartLinkRepository !== null && $this->monitorIdentity !== null) {
            $smartLink = $this->smartLinkRepository->findByAlias($resolution->alias);
            if ($smartLink === null) {
                throw new RuntimeException('Smart alias was not found.');
            }

            $footer = $this->monitorIdentity->forSchool($smartLink->schoolId);
        }

        return MonitorSnapshot::fromReadModel($resolution->alias, $readModel, $footer);
    }
}
