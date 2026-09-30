<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AttemptRepository;
use App\Core\Request;
use App\Core\Response;
use App\Core\SubmissionService;
use App\Core\VisitorIdentityCookie;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class PublicParticipantSubmitController
{
    public function __construct(private readonly AttemptRepository $attempts, private readonly SubmissionService $submissions, private readonly VisitorIdentityCookie $visitorCookie) {}

    public function submit(Request $request, string $attemptUuid): Response
    {
        $visitorUuid = $request->cookie(VisitorIdentityCookie::NAME);
        if (!$this->visitorCookie->isValidUuidV4($attemptUuid) || !$this->visitorCookie->isValidUuidV4($visitorUuid)) return $this->error('not_found', 404);
        $attempt = $this->attempts->findByAttemptUuid($attemptUuid);
        if ($attempt === null || !hash_equals($attempt->visitorUuid, $visitorUuid)) return $this->error('not_found', 404);
        if (!$this->acceptsJson($request->header('Content-Type'))) return $this->error('unsupported_media_type', 415);
        try { $answers = $this->answers($request->body); } catch (JsonException) { return $this->error('invalid_json', 400); } catch (\InvalidArgumentException) { return $this->error('invalid_answers', 422); }
        try {
            $outcome = $this->submissions->submit($attemptUuid, $answers, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        } catch (\InvalidArgumentException) { return $this->error('invalid_answers', 422); } catch (\Throwable) { return $this->error('internal_error', 500); }
        return Response::json(['ok' => true, 'attempt_uuid' => $outcome->attemptUuid, 'status' => 'completed', 'already_completed' => $outcome->alreadyCompleted], $outcome->alreadyCompleted ? 200 : 201);
    }

    /** @return array<int, int> */
    private function answers(string $body): array
    {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload) || array_keys($payload) !== ['answers'] || !is_array($payload['answers']) || !array_is_list($payload['answers'])) throw new \InvalidArgumentException('Invalid payload.');
        $answers = [];
        foreach ($payload['answers'] as $answer) {
            if (!is_array($answer) || array_is_list($answer) || count($answer) !== 2 || !array_key_exists('question_id', $answer) || !array_key_exists('option_id', $answer) || !is_string($answer['question_id']) || !is_string($answer['option_id'])) throw new \InvalidArgumentException('Invalid item.');
            $questionId = $this->identifier($answer['question_id'], 'question');
            if (isset($answers[$questionId])) throw new \InvalidArgumentException('Duplicate question.');
            $answers[$questionId] = $this->identifier($answer['option_id'], 'option');
        }
        return $answers;
    }

    private function identifier(string $value, string $kind): int
    {
        if (preg_match('/^' . $kind . '-([1-9][0-9]*)$/', $value, $matches) !== 1) throw new \InvalidArgumentException('Invalid identifier.');
        $digits = $matches[1]; $maximum = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) throw new \InvalidArgumentException('Out of range.');
        return (int) $digits;
    }

    private function acceptsJson(?string $contentType): bool { return $contentType !== null && preg_match('/^application\/json(?:\s*;\s*charset=[A-Za-z0-9._-]+)?$/i', trim($contentType)) === 1; }
    private function error(string $code, int $status): Response { return Response::json(['ok' => false, 'error' => $code], $status); }
}
