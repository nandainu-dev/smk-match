<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ParticipantStartService;
use App\Core\Request;
use App\Core\Response;
use App\Core\SmartLinkService;
use App\Core\VisitorIdentityCookie;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

final class PublicParticipantStartController
{
    public function __construct(
        private readonly SmartLinkService $smartLinks,
        private readonly ParticipantStartService $starts,
        private readonly VisitorIdentityCookie $visitorCookie,
    ) {
    }

    public function start(Request $request, string $alias): Response
    {
        if (!$this->acceptsJson($request->header('Content-Type'))) {
            return $this->error('unsupported_media_type', 415);
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return $this->error('missing_idempotency_key', 400);
        }
        if (!$this->isUuidV4($idempotencyKey)) {
            return $this->error('invalid_request', 422);
        }

        try {
            $payload = $this->payload($request->body);
        } catch (JsonException) {
            return $this->error('invalid_json', 400);
        } catch (\InvalidArgumentException) {
            return $this->error('invalid_request', 422);
        }

        try {
            $resolution = $this->smartLinks->resolve($alias);
        } catch (\Throwable) {
            return $this->error('not_found', 404);
        }

        $visitor = $this->visitorCookie->resolve($request);

        try {
            $outcome = $this->starts->start(
                $resolution->campaignId,
                $visitor['visitor_uuid'],
                $idempotencyKey,
                $payload['full_name'],
                $payload['origin_school'],
                $payload['class_name'],
                $payload['phone'],
                $payload['marketing_consent'],
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'Attempt UUID conflicts')) {
                return $this->error('idempotency_conflict', 409, $visitor['set_cookie']);
            }

            return $this->error('campaign_unavailable', 409, $visitor['set_cookie']);
        } catch (\Throwable) {
            return $this->error('internal_error', 500, $visitor['set_cookie']);
        }

        return $this->json([
            'ok' => true,
            'attempt_uuid' => $outcome->attempt->attemptUuid,
            'status' => $outcome->attempt->status,
            'already_started' => $outcome->alreadyStarted,
        ], $outcome->alreadyStarted ? 200 : 201, $visitor['set_cookie']);
    }

    /** @return array{full_name: string, origin_school: ?string, class_name: ?string, phone: string, marketing_consent: bool} */
    private function payload(string $body): array
    {
        $trimmedBody = trim($body);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || str_starts_with($trimmedBody, '[')) {
            throw new \InvalidArgumentException('Request JSON root must be an object.');
        }

        $allowedFields = ['full_name', 'origin_school', 'class_name', 'phone', 'marketing_consent'];
        foreach (array_keys($payload) as $field) {
            if (!is_string($field) || !in_array($field, $allowedFields, true)) {
                throw new \InvalidArgumentException('Request contains an unsupported field.');
            }
        }

        if (!isset($payload['full_name']) || !is_string($payload['full_name']) || trim($payload['full_name']) === '') {
            throw new \InvalidArgumentException('Full name is required.');
        }
        if (!array_key_exists('marketing_consent', $payload) || !is_bool($payload['marketing_consent'])) {
            throw new \InvalidArgumentException('Marketing consent must be a boolean.');
        }

        $optionalFields = [];
        foreach (['origin_school', 'class_name'] as $field) {
            $value = $payload[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException('Optional identity fields must be strings or null.');
            }
            $optionalFields[$field] = $value;
        }

        $phone = $payload['phone'] ?? null;
        if (!is_string($phone) || !$this->isValidIndonesianWhatsapp($phone)) {
            throw new \InvalidArgumentException('A valid WhatsApp number is required.');
        }

        return [
            'full_name' => $payload['full_name'],
            'origin_school' => $optionalFields['origin_school'],
            'class_name' => $optionalFields['class_name'],
            'phone' => $phone,
            'marketing_consent' => $payload['marketing_consent'],
        ];
    }

    private function isValidIndonesianWhatsapp(string $phone): bool
    {
        if (preg_match('/^08[0-9]{8,11}$/', $phone) !== 1 || preg_match('/^([0-9])\1+$/', $phone) === 1) {
            return false;
        }

        $subscriberNumber = substr($phone, 2);

        return !str_contains($subscriberNumber, '123456789')
            && !str_contains($subscriberNumber, '987654321')
            && preg_match('/^([0-9]{2})(?:\1){3,4}$/', $subscriberNumber) !== 1;
    }

    private function acceptsJson(?string $contentType): bool
    {
        return $contentType !== null
            && preg_match('/^application\/json(?:\s*;\s*charset=[A-Za-z0-9._-]+)?$/i', trim($contentType)) === 1;
    }

    private function isUuidV4(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function error(string $code, int $status, ?string $setCookie = null): Response
    {
        return $this->json(['ok' => false, 'error' => $code], $status, $setCookie);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status, ?string $setCookie = null): Response
    {
        $response = Response::json($payload, $status);
        $headers = $response->headers;
        if ($setCookie !== null) {
            $headers['Set-Cookie'] = $setCookie;
        }

        return new Response($response->body, $response->status, $headers);
    }
}
