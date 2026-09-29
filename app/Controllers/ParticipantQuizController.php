<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\QuizProvider;
use App\Core\Response;

final class ParticipantQuizController
{
    public function __construct(
        private readonly Config $config,
        private readonly QuizProvider $quizzes,
    ) {
    }

    public function index(): Response
    {
        $definition = $this->quizzes->development();
        $definition->validate();

        $quiz = [
            'name' => $definition->name,
            'questions' => [],
        ];

        foreach ($definition->questions as $question) {
            $safeQuestion = [
                'id' => $question['id'],
                'text' => $question['text'],
                'order' => $question['order'],
                'options' => [],
            ];

            foreach ($question['options'] as $option) {
                $safeQuestion['options'][] = [
                    'id' => $option['id'],
                    'text' => $option['text'],
                    'order' => $option['order'],
                ];
            }

            $quiz['questions'][] = $safeQuestion;
        }

        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $quizJson = json_encode(
            $quiz,
            JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );

        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/participant-quiz.php';

        return new Response((string) ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
