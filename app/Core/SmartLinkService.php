<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class SmartLinkService
{
    /** @var list<string> */
    private const RESERVED_ALIASES = [
        'health',
        'result',
        'assets',
        'go',
        'play',
        'admin',
        'api',
        'index.php',
    ];

    public function __construct(
        private readonly SmartLinkRepository $smartLinkRepository,
        private readonly CampaignRepository $campaignRepository,
        private readonly CampaignBatchRepository $campaignBatchRepository,
    ) {
    }

    public function create(
        int $schoolId,
        int $campaignId,
        string $name,
        string $alias,
        ?string $source = null,
        bool $isActive = true,
    ): SmartLink {
        $canonicalAlias = $this->canonicalAlias($alias);
        $campaign = $this->requireCampaign($campaignId);

        if ($campaign->schoolId !== $schoolId) {
            throw new RuntimeException('Smart link school does not match the target campaign school.');
        }

        return $this->smartLinkRepository->create(
            $schoolId,
            $campaign->id,
            $name,
            $canonicalAlias,
            $source,
            $isActive,
        );
    }

    public function resolve(string $alias): SmartLinkResolution
    {
        $canonicalAlias = $this->canonicalAlias($alias);
        $smartLink = $this->smartLinkRepository->findByAlias($canonicalAlias);
        if ($smartLink === null) {
            throw new RuntimeException('Smart alias was not found.');
        }

        if (!$smartLink->isActive) {
            throw new RuntimeException('Smart alias is not active.');
        }

        if ($smartLink->campaignId === null) {
            throw new RuntimeException('Smart alias has no internal campaign target.');
        }

        $campaign = $this->requireCampaign($smartLink->campaignId);
        if ($smartLink->schoolId !== $campaign->schoolId) {
            throw new RuntimeException('Smart alias school does not match the target campaign school.');
        }

        if ($campaign->status !== 'active') {
            throw new RuntimeException('Smart alias campaign is not playable.');
        }

        $activeBatch = $this->campaignBatchRepository->findActiveByCampaignId($campaign->id);
        if ($activeBatch === null) {
            throw new RuntimeException('Smart alias campaign has no active batch.');
        }

        if ($activeBatch->status !== CampaignBatch::STATUS_ACTIVE) {
            throw new RuntimeException('Persistence invariant violation: active campaign batch marker has an invalid status.');
        }

        return new SmartLinkResolution(
            $smartLink->alias,
            $campaign->id,
            $activeBatch->id,
            $activeBatch->quizVersionId,
        );
    }

    /** @return list<string> */
    public static function reservedAliases(): array
    {
        return self::RESERVED_ALIASES;
    }

    private function canonicalAlias(string $alias): string
    {
        $canonicalAlias = SmartLink::canonicalAlias($alias);

        if (in_array($canonicalAlias, self::RESERVED_ALIASES, true)) {
            throw new \InvalidArgumentException('Smart alias is reserved.');
        }

        return $canonicalAlias;
    }

    private function requireCampaign(int $campaignId): Campaign
    {
        if ($campaignId < 1) {
            throw new \InvalidArgumentException('Campaign identity must be positive.');
        }

        $campaign = $this->campaignRepository->findById($campaignId);
        if ($campaign === null) {
            throw new RuntimeException('Smart alias target campaign does not exist.');
        }

        return $campaign;
    }
}
