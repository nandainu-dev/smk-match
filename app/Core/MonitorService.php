<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MonitorService
{
    public function __construct(
        private readonly SmartLinkService $smartLinks,
        private readonly MonitorReadRepository $monitorReads,
    ) {
    }

    public function read(string $alias, int $recentLimit = 20): MonitorSnapshot
    {
        $resolution = $this->smartLinks->resolve($alias);
        $readModel = $this->monitorReads->readActiveBatch($resolution->campaignId, $recentLimit);

        if ($readModel === null) {
            throw new RuntimeException('Monitor campaign has no active batch.');
        }

        if ($readModel['batch']['campaign_id'] !== $resolution->campaignId) {
            throw new RuntimeException('Monitor batch does not belong to the resolved campaign.');
        }

        return MonitorSnapshot::fromReadModel($resolution->alias, $readModel);
    }
}
