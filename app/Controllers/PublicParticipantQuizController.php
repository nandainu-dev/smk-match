<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ParticipantQuizDeliveryService;
use App\Core\Request;
use App\Core\Response;
use App\Core\VisitorIdentityCookie;
use DomainException;
use InvalidArgumentException;

final class PublicParticipantQuizController
{
    public function __construct(
        private readonly ParticipantQuizDeliveryService $deliveries,
        private readonly VisitorIdentityCookie $visitorCookie,
    ) {
    }

    public function quiz(Request $request, string $attemptUuid): Response
    {
        $visitorUuid = $request->cookie(VisitorIdentityCookie::NAME);
        if (!$this->visitorCookie->isValidUuidV4($visitorUuid)) {
            return $this->notFound();
        }

        try {
            $delivery = $this->deliveries->deliver($attemptUuid, $visitorUuid);
        } catch (DomainException) {
            return Response::json(['ok' => false, 'error' => 'attempt_completed'], 409);
        } catch (InvalidArgumentException) {
            return $this->notFound();
        } catch (\Throwable) {
            return Response::json(['ok' => false, 'error' => 'internal_error'], 500);
        }

        return Response::json([
            'ok' => true,
            'attempt_uuid' => $delivery['attempt_uuid'],
            'status' => $delivery['status'],
            'quiz' => $delivery['quiz'],
        ]);
    }

    private function notFound(): Response
    {
        return Response::json(['ok' => false, 'error' => 'not_found'], 404);
    }
}
