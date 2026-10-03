<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminCampaignService;
use App\Core\AdminHistoryXlsxExporter;
use App\Core\AdminSession;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class AdminCampaignController
{
    public function __construct(
        private readonly Config $config,
        private readonly AdminSession $sessions,
        private readonly AdminCampaignService $campaigns,
        private readonly AdminHistoryXlsxExporter $exporter = new AdminHistoryXlsxExporter(),
    ) {
    }

    public function index(Request $request): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }

        try {
            $campaignId = $this->optionalPositiveQueryId($request, 'campaign_id');
            $dashboard = $this->campaigns->campaignDashboard($identity['school_id'], $campaignId);
        } catch (\InvalidArgumentException $exception) {
            return $this->renderCampaigns([], null, [], [], [], [], $exception->getMessage(), 422);
        } catch (\RuntimeException) {
            return $this->notFound();
        }

        return $this->renderCampaigns(
            $dashboard['campaigns'],
            $dashboard['selected_campaign'],
            $dashboard['batches'],
            $dashboard['smart_links'],
            $dashboard['published_versions'],
            $this->suggestedQrBaseUrls($request),
        );
    }

    public function generateQr(Request $request, string $campaignId, string $alias): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return $this->invalidRequest();
        }

        try {
            $id = $this->positiveRouteId($campaignId);
            $form = $request->formValues();
            $baseUrl = isset($form['base_url']) && is_string($form['base_url']) ? $form['base_url'] : '';
            $this->campaigns->generateQrTarget($identity['school_id'], $id, $alias, $baseUrl);
        } catch (\InvalidArgumentException $exception) {
            return $this->renderCampaignError($identity['school_id'], $exception->getMessage(), 422);
        } catch (\RuntimeException) {
            return $this->notFound();
        }

        return $this->redirect('/admin/campaigns?campaign_id=' . $id);
    }

    public function reset(Request $request, string $campaignId): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return $this->invalidRequest();
        }

        try {
            $id = $this->positiveRouteId($campaignId);
            $form = $request->formValues();
            $confirmation = isset($form['confirmation']) && is_string($form['confirmation']) ? $form['confirmation'] : '';
            $this->campaigns->resetActiveBatch($identity['school_id'], $id, $confirmation);
        } catch (\InvalidArgumentException $exception) {
            return $this->renderCampaignError($identity['school_id'], $exception->getMessage(), 422);
        } catch (\RuntimeException) {
            return $this->notFound();
        }

        return $this->redirect('/admin/campaigns?campaign_id=' . $id);
    }

    public function activateQuizVersion(Request $request, string $campaignId, string $quizVersionId): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }
        if (!$this->validCsrf($request)) {
            return $this->invalidRequest();
        }

        try {
            $campaignIdentity = $this->positiveRouteId($campaignId);
            $versionIdentity = $this->positiveRouteId($quizVersionId);
            $form = $request->formValues();
            $confirmation = isset($form['confirmation']) && is_string($form['confirmation']) ? $form['confirmation'] : '';
            $this->campaigns->activatePublishedQuizVersion(
                $identity['school_id'],
                $campaignIdentity,
                $versionIdentity,
                $confirmation,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->renderCampaignError($identity['school_id'], $exception->getMessage(), 422);
        } catch (\RuntimeException) {
            return $this->notFound();
        }

        return $this->redirect('/admin/campaigns?campaign_id=' . $campaignIdentity);
    }

    public function history(Request $request): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }

        try {
            $report = $this->campaigns->history(
                $identity['school_id'],
                $this->optionalPositiveQueryId($request, 'campaign_id'),
                $this->optionalPositiveQueryId($request, 'batch_id'),
                $this->optionalDateQuery($request, 'from'),
                $this->optionalDateQuery($request, 'to'),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->renderHistory([], [], [], [], ['campaign_id' => null, 'batch_id' => null, 'from' => null, 'to' => null], $exception->getMessage(), 422);
        } catch (\RuntimeException) {
            return $this->notFound();
        }

        return $this->renderHistory(
            $report['campaigns'],
            $report['batches'],
            $report['rows'],
            $report['summary'],
            $report['filters'],
        );
    }

    public function export(Request $request): Response
    {
        $identity = $this->identity($request);
        if ($identity === null) {
            return $this->loginRequired();
        }

        try {
            $report = $this->campaigns->history(
                $identity['school_id'],
                $this->optionalPositiveQueryId($request, 'campaign_id'),
                $this->optionalPositiveQueryId($request, 'batch_id'),
                $this->optionalDateQuery($request, 'from'),
                $this->optionalDateQuery($request, 'to'),
            );
        } catch (\InvalidArgumentException $exception) {
            return new Response($exception->getMessage(), 422, ['Content-Type' => 'text/plain; charset=utf-8']);
        } catch (\RuntimeException) {
            return $this->notFound();
        }

        return $this->exporter->export($report);
    }

    /** @return array{admin_id: int, school_id: int}|null */
    private function identity(Request $request): ?array
    {
        $this->sessions->start($request->isHttps);

        return $this->sessions->identity();
    }

    private function validCsrf(Request $request): bool
    {
        $form = $request->formValues();

        return $this->sessions->verifyCsrf(isset($form['csrf_token']) && is_string($form['csrf_token']) ? $form['csrf_token'] : null);
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

    private function positiveRouteId(string $value): int
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Campaign identity is invalid.');
        }

        return (int) $value;
    }

    /** @param list<\App\Core\Campaign> $campaigns @param list<\App\Core\CampaignBatch> $batches @param list<array{name: string, alias: string, is_active: bool, qr_target_url: ?string, monitor_target: string}> $smartLinks @param list<array{id: int, version_number: int, name: string, status: string}> $publishedVersions @param list<string> $qrBaseUrlSuggestions */
    private function renderCampaigns(array $campaigns, ?\App\Core\Campaign $selectedCampaign, array $batches, array $smartLinks, array $publishedVersions, array $qrBaseUrlSuggestions = [], ?string $message = null, int $status = 200): Response
    {
        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrfToken = htmlspecialchars($this->sessions->csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = $message === null ? null : htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $adminScripts = ['/assets/js/vendor/qrcode-generator.js', '/assets/js/admin-smart-qr.js'];

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/admin-campaigns.php';

        return new Response((string) ob_get_clean(), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function renderCampaignError(int $schoolId, string $message, int $status): Response
    {
        $dashboard = $this->campaigns->campaignDashboard($schoolId, null);

        return $this->renderCampaigns(
            $dashboard['campaigns'],
            $dashboard['selected_campaign'],
            $dashboard['batches'],
            $dashboard['smart_links'],
            $dashboard['published_versions'],
            [],
            $message,
            $status,
        );
    }

    /** @return list<string> */
    private function suggestedQrBaseUrls(Request $request): array
    {
        $suggestions = [];
        $configured = $this->config->publicBaseUrl();
        $configuredParts = parse_url($configured);
        if (is_array($configuredParts)
            && isset($configuredParts['scheme'], $configuredParts['host'])
            && is_string($configuredParts['host'])
            && !$this->isLoopbackHost($configuredParts['host'])) {
            $suggestions[] = rtrim($configured, '/');
        }

        $port = $this->suggestedPort($request, $configuredParts);
        $scheme = $request->isHttps ? 'https' : 'http';
        $requestHost = $request->header('Host');
        if (is_string($requestHost)) {
            $host = preg_replace('/:\d+\z/D', '', $requestHost);
            if (is_string($host) && $this->isPrivateIpv4($host)) {
                $suggestions[] = $scheme . '://' . $host . ':' . $port;
            }
        }
        $addresses = gethostbynamel(gethostname()) ?: [];
        $addresses = array_values(array_unique(array_filter($addresses, fn (string $address): bool => $this->isPrivateIpv4($address))));
        sort($addresses, SORT_STRING);
        foreach ($addresses as $address) {
            $suggestions[] = $scheme . '://' . $address . ':' . $port;
        }

        return array_values(array_unique($suggestions));
    }

    /** @param array<string, mixed>|false $configuredParts */
    private function suggestedPort(Request $request, array|false $configuredParts): int
    {
        $host = $request->header('Host');
        if (is_string($host) && preg_match('/:(\d+)\z/D', $host, $matches) === 1) {
            return (int) $matches[1];
        }
        if (is_array($configuredParts) && isset($configuredParts['port'])) {
            return (int) $configuredParts['port'];
        }

        return $request->isHttps ? 443 : 80;
    }

    private function isLoopbackHost(string $host): bool
    {
        return strtolower($host) === 'localhost' || str_starts_with($host, '127.');
    }

    private function isPrivateIpv4(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $octets = array_map('intval', explode('.', $address));

        return $octets[0] === 10
            || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
            || ($octets[0] === 192 && $octets[1] === 168);
    }

    /** @param list<\App\Core\Campaign> $campaigns @param list<\App\Core\CampaignBatch> $batches @param list<array<string, mixed>> $rows @param list<array{code: string, name: string, dominant_count: int, score_average_percentage: float}> $summary @param array{campaign_id: ?int, batch_id: ?int, from: ?string, to: ?string} $filters */
    private function renderHistory(array $campaigns, array $batches, array $rows, array $summary, array $filters, ?string $message = null, int $status = 200): Response
    {
        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrfToken = htmlspecialchars($this->sessions->csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = $message === null ? null : htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/admin-history.php';

        return new Response((string) ob_get_clean(), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function invalidRequest(): Response
    {
        return new Response('Permintaan tidak dapat diproses.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function loginRequired(): Response
    {
        return $this->redirect('/admin/login');
    }

    private function notFound(): Response
    {
        return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function redirect(string $location): Response
    {
        return new Response('', 302, ['Location' => $location]);
    }
}
