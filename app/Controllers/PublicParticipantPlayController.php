<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\QuizVersionRepository;
use App\Core\QuizVersionProgramPresentationRepository;
use App\Core\Response;
use App\Core\SmartLinkService;

final class PublicParticipantPlayController
{
    public function __construct(
        private readonly Config $config,
        private readonly SmartLinkService $smartLinks,
        private readonly QuizVersionProgramPresentationRepository $presentations,
        private readonly QuizVersionRepository $quizVersions,
    ) {
    }

    public function play(string $alias): Response
    {
        try {
            $resolution = $this->smartLinks->resolve($alias);
        } catch (\Throwable) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $version = $this->quizVersions->findById($resolution->quizVersionId);
        if ($version === null) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $presentationPrograms = [];
        foreach ($this->presentations->findAllByQuizVersionId($resolution->quizVersionId) as $presentation) {
            $presentationPrograms[] = [
                'code' => $presentation->programCodeSnapshot,
                'display_name' => $presentation->programNameSnapshot,
                'mascot_path' => $presentation->mascotPathSnapshot,
                'primary_color' => $presentation->primaryColorSnapshot,
                'accent_color' => $presentation->accentColorSnapshot,
            ];
        }

        $quizJson = json_encode([
            'mode' => 'real',
            'alias' => $resolution->alias,
            'question_count' => count($version->definition()->questions),
            'presentation_programs' => $presentationPrograms,
        ], JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR);

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/participant-quiz.php';

        return new Response((string) ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
