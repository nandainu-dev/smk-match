<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class AdminAnalyticsService
{
    public function __construct(
        private readonly Config $config,
        private readonly AdminCampaignRepository $campaigns,
        private readonly AnalyticsReadRepository $analytics,
    ) {
    }

    /**
     * @return array{
     *     filters: array{campaign_id: ?int, batch_id: ?int, date_from: ?string, date_to: ?string},
     *     campaigns: list<Campaign>,
     *     batches: list<CampaignBatch>,
     *     summary: array<string, mixed>,
     *     batch_trends: list<array<string, mixed>>,
     *     campaign_trends: list<array<string, mixed>>,
     *     program_cohorts: list<array<string, mixed>>,
     *     comparison_cohorts: list<array<string, mixed>>
     * }
     */
    public function read(
        int $schoolId,
        ?int $campaignId,
        ?int $batchId,
        ?string $dateFrom,
        ?string $dateTo,
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

        [$fromDate, $fromUtc] = $this->localDateStart($dateFrom);
        [$toDate, $untilUtc] = $this->localDateEndExclusive($dateTo);
        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            throw new \InvalidArgumentException('Date range is reversed.');
        }

        $analytics = $this->analytics->read($schoolId, $campaignId, $batchId, $fromUtc, $untilUtc);

        return [
            'filters' => [
                'campaign_id' => $campaignId,
                'batch_id' => $batchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'campaigns' => $campaigns,
            'batches' => $batches,
            ...$analytics,
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
