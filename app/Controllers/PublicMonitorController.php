<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\MonitorService;
use App\Core\Request;
use App\Core\Response;
use InvalidArgumentException;
use RuntimeException;

final class PublicMonitorController
{
    private const DEFAULT_RECENT_LIMIT = 20;

    public function __construct(private readonly MonitorService $monitors)
    {
    }

    public function show(Request $request, string $alias): Response
    {
        try {
            $recentLimit = $this->recentLimit($request);
        } catch (InvalidArgumentException) {
            return $this->error('invalid_recent_limit', 422);
        }

        try {
            $snapshot = $this->monitors->read($alias, $recentLimit);
        } catch (InvalidArgumentException) {
            return $this->error('not_found', 404);
        } catch (RuntimeException $exception) {
            if ($this->isPublicUnavailable($exception)) {
                return $this->error('not_found', 404);
            }

            return $this->error('internal_error', 500);
        } catch (\Throwable) {
            return $this->error('internal_error', 500);
        }

        return $this->json(['ok' => true, 'monitor' => $snapshot->toArray()], 200);
    }

    private function recentLimit(Request $request): int
    {
        $values = $request->queryValues('recentLimit');
        if ($values === null) {
            return self::DEFAULT_RECENT_LIMIT;
        }

        if (count($values) !== 1 || !is_string($values[0])) {
            throw new InvalidArgumentException('Invalid recent limit.');
        }

        $value = $values[0];
        if (preg_match('/^(?:[1-9]|[1-4][0-9]|50)$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid recent limit.');
        }

        return (int) $value;
    }

    private function isPublicUnavailable(RuntimeException $exception): bool
    {
        return str_starts_with($exception->getMessage(), 'Smart alias ')
            || in_array($exception->getMessage(), [
                'Monitor campaign has no active batch.',
                'Monitor batch does not belong to the resolved campaign.',
            ], true);
    }

    private function error(string $code, int $status): Response
    {
        return $this->json(['ok' => false, 'error' => $code], $status);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status): Response
    {
        $response = Response::json($payload, $status);
        $headers = $response->headers;
        $headers['Cache-Control'] = 'no-store, max-age=0';

        return new Response($response->body, $response->status, $headers);
    }
}
