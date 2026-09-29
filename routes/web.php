<?php
declare(strict_types=1);

use App\Controllers\ParticipantQuizController;
use App\Core\Config;
use App\Core\ProgramProvider;
use App\Core\QuizProvider;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/** @return Router */
return static function (Config $config): Router {
    $router = new Router();
    $participantQuiz = new ParticipantQuizController(
        $config,
        new QuizProvider(new ProgramProvider()),
    );
    $router->get('/', static fn (Request $request): Response => $participantQuiz->index());
    $router->get('/health', static fn (Request $request): Response => Response::json([
        'status' => 'ok',
        'app' => $config->string('APP_NAME'),
        'environment' => $config->string('APP_ENV'),
        'php' => ['supported' => true],
    ]));

    return $router;
};
