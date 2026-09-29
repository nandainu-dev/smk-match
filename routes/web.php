<?php
declare(strict_types=1);

use App\Controllers\ParticipantQuizController;
use App\Controllers\ParticipantResultController;
use App\Core\Config;
use App\Core\ProgramProvider;
use App\Core\QuizProvider;
use App\Core\Request;
use App\Core\Response;
use App\Core\ResultPresentationFixtureProvider;
use App\Core\Router;

/** @return Router */
return static function (Config $config): Router {
    $router = new Router();
    $participantQuiz = new ParticipantQuizController(
        $config,
        new QuizProvider(new ProgramProvider()),
    );
    $participantResult = new ParticipantResultController(
        $config,
        new ResultPresentationFixtureProvider(new ProgramProvider()),
    );
    $router->get('/', static fn (Request $request): Response => $participantQuiz->index());
    $router->get('/result', static fn (Request $request): Response => $participantResult->preview('error'));
    $router->get('/result/dkv', static fn (Request $request): Response => $participantResult->preview('dkv'));
    $router->get('/result/mplb', static fn (Request $request): Response => $participantResult->preview('mplb'));
    $router->get('/result/pm', static fn (Request $request): Response => $participantResult->preview('pm'));
    $router->get('/result/tie', static fn (Request $request): Response => $participantResult->preview('tie'));
    $router->get('/result/error', static fn (Request $request): Response => $participantResult->preview('error'));
    $router->get('/health', static fn (Request $request): Response => Response::json([
        'status' => 'ok',
        'app' => $config->string('APP_NAME'),
        'environment' => $config->string('APP_ENV'),
        'php' => ['supported' => true],
    ]));

    return $router;
};
