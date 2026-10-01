<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class AdminCampaignService
{
    private const RESET_CONFIRMATION = 'RESET';

    public function __construct(
        private readonly Config $config,
        private readonly AdminCampaignRepository $campaigns,
        private readonly AdminHistoryReadRepository $history,
        private readonly CampaignService $campaignService,
    ) {
    }

    /**
     * @return array{
     *     campaigns: list<Campaign>,
     *     selected_campaign: ?Campaign,
     *     batches: list<CampaignBatch>,
     *     smart_links: list<array{name: string, alias: string, is_active: bool, target: string}>
     * }
     */
    public function campaignDashboard(int $schoolId, ?int $campaignId): array
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $campaigns = $this->campaigns->listCampaignsForSchool($schoolId);
        $selectedCampaign = null;

        if ($campaignId !== null) {
            $selectedCampaign = $this->requireCampaign($schoolId, $campaignId);
        } elseif ($campaigns !== []) {
            $selectedCampaign = $campaigns[0];
        }

        if ($selectedCampaign === null) {
            return [
                'campaigns' => $campaigns,
                'selected_campaign' => null,
                'batches' => [],
                'smart_links' => [],
            ];
        }

        $links = [];
        foreach ($this->campaigns->listSmartLinksForCampaign($schoolId, $selectedCampaign->id) as $link) {
            $links[] = [
                'name' => $link->name,
                'alias' => $link->alias,
                'is_active' => $link->isActive,
                'target' => $this->smartLinkTarget($link->alias),
            ];
        }

        return [
            'campaigns' => $campaigns,
            'selected_campaign' => $selectedCampaign,
            'batches' => $this->campaigns->listBatchesForCampaign($schoolId, $selectedCampaign->id),
            'smart_links' => $links,
        ];
    }

    public function resetActiveBatch(int $schoolId, int $campaignId, string $confirmation): CampaignBatch
    {
        $this->assertPositiveId($schoolId, 'School identity');
        $campaign = $this->requireCampaign($schoolId, $campaignId);

        if ($confirmation !== self::RESET_CONFIRMATION) {
            throw new \InvalidArgumentException('Batch reset confirmation is required.');
        }

        return $this->campaignService->resetActiveBatch($campaign->id);
    }

    /**
     * @return array{
     *     filters: array{campaign_id: ?int, batch_id: ?int, from: ?string, to: ?string},
     *     campaigns: list<Campaign>,
     *     batches: list<CampaignBatch>,
     *     rows: list<array<string, mixed>>,
     *     summary: list<array{code: string, name: string, dominant_count: int, score_average_percentage: float}>
     * }
     */
    public function history(
        int $schoolId,
        ?int $campaignId,
        ?int $batchId,
        ?string $from,
        ?string $to,
    ): array {
        $this->assertPositiveId($schoolId, 'School identity');
        $campaigns = $this->campaigns->listCampaignsForSchool($schoolId);
        $batches = [];

        if ($campaignId !== null) {
            $this->requireCampaign($schoolId, $campaignId);
            $batches = $this->campaigns->listBatchesForCampaign($schoolId, $campaignId);
        }

        if ($batchId !== null) {
            if ($campaignId === null) {
                throw new \InvalidArgumentException('Campaign selection is required before batch selection.');
            }
            if ($this->campaigns->findBatchForCampaign($schoolId, $campaignId, $batchId) === null) {
                throw new RuntimeException('Campaign batch was not found.');
            }
        }

        [$fromDate, $fromUtc] = $this->localDateStart($from);
        [$toDate, $untilUtc] = $this->localDateEndExclusive($to);
        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            throw new \InvalidArgumentException('Date range is reversed.');
        }

        $report = $this->history->read($schoolId, $campaignId, $batchId, $fromUtc, $untilUtc);

        return [
            'filters' => [
                'campaign_id' => $campaignId,
                'batch_id' => $batchId,
                'from' => $from,
                'to' => $to,
            ],
            'campaigns' => $campaigns,
            'batches' => $batches,
            'rows' => $report['rows'],
            'summary' => $report['summary'],
        ];
    }

    private function requireCampaign(int $schoolId, int $campaignId): Campaign
    {
        $this->assertPositiveId($campaignId, 'Campaign identity');
        $campaign = $this->campaigns->findCampaignForSchool($schoolId, $campaignId);
        if ($campaign === null) {
            throw new RuntimeException('Campaign was not found.');
        }

        return $campaign;
    }

    private function smartLinkTarget(string $alias): string
    {
        return rtrim($this->config->string('APP_URL'), '/') . '/go/' . rawurlencode(SmartLink::canonicalAlias($alias));
    }

    /** @return array{0: ?string, 1: ?string} */
    private function localDateStart(?string $date): array
    {
        if ($date === null || trim($date) === '') {
            return [null, null];
        }

        $local = $this->parseLocalDate($date);

        return [$local->format('Y-m-d'), $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function localDateEndExclusive(?string $date): array
    {
        if ($date === null || trim($date) === '') {
            return [null, null];
        }

        $local = $this->parseLocalDate($date);
        $until = $local->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));

        return [$local->format('Y-m-d'), $until->format('Y-m-d H:i:s')];
    }

    private function parseLocalDate(string $date): DateTimeImmutable
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $date) !== 1) {
            throw new \InvalidArgumentException('Date must use YYYY-MM-DD.');
        }

        $timezone = new DateTimeZone($this->config->string('APP_TIMEZONE'));
        $local = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($local === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $local->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Date is invalid.');
        }

        return $local;
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }
}
