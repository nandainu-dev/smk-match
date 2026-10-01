<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAnalyticsService;
use App\Core\AdminSession;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class AdminAnalyticsController
{
    public function __construct(
        private readonly Config $config,
        private readonly AdminSession $sessions,
        private readonly AdminAnalyticsService $analytics,
    ) {
    }

    public function index(Request $request): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return new Response('', 302, ['Location' => '/admin/login']);
        }

        try {
            $report = $this->analytics->read(
                $identity['school_id'],
                $this->optionalPositiveQueryId($request, 'campaign_id'),
                $this->optionalPositiveQueryId($request, 'batch_id'),
                $this->optionalDateQuery($request, 'date_from'),
                $this->optionalDateQuery($request, 'date_to'),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->render([], [], [], [], [], [], [], ['campaign_id' => null, 'batch_id' => null, 'date_from' => null, 'date_to' => null], $exception->getMessage(), 422);
        } catch (\RuntimeException) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $this->render(
            $report['campaigns'],
            $report['batches'],
            $report['summary'],
            $report['batch_trends'],
            $report['campaign_trends'],
            $report['program_cohorts'],
            $report['comparison_cohorts'],
            $report['filters'],
        );
    }

    /** @return array{admin_id: int, school_id: int}|null */
    private function identity(Request $request): ?array
    {
        $this->sessions->start($request->isHttps);

        return $this->sessions->identity();
    }

    private function optionalPositiveQueryId(Request $request, string $name): ?int
    {
        $values = $request->queryValues($name);
        if ($values === null || $values === ['']) {
            return null;
        }
        if (count($values) !== 1 || !is_string($values[0]) || preg_match('/\A[1-9][0-9]*\z/D', $values[0]) !== 1) {
            throw new \InvalidArgumentException('Parameter ' . $name . ' is invalid.');
        }

        return (int) $values[0];
    }

    private function optionalDateQuery(Request $request, string $name): ?string
    {
        $values = $request->queryValues($name);
        if ($values === null || $values === ['']) {
            return null;
        }
        if (count($values) !== 1 || !is_string($values[0])) {
            throw new \InvalidArgumentException('Parameter ' . $name . ' is invalid.');
        }

        return $values[0];
    }

    /** @param list<\App\Core\Campaign> $campaigns @param list<\App\Core\CampaignBatch> $batches @param array<string, mixed> $summary @param list<array<string, mixed>> $batchTrends @param list<array<string, mixed>> $campaignTrends @param list<array<string, mixed>> $programCohorts @param list<array<string, mixed>> $comparisonCohorts @param array<string, ?int|string> $filters */
    private function render(array $campaigns, array $batches, array $summary, array $batchTrends, array $campaignTrends, array $programCohorts, array $comparisonCohorts, array $filters, ?string $message = null, int $status = 200): Response
    {
        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = $message === null ? null : htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/admin-analytics.php';

        return new Response((string) ob_get_clean(), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
