<?php
declare(strict_types=1);

use App\Controllers\PublicCampaignEntryController;
use App\Controllers\PublicParticipantStartController;
use App\Controllers\PublicParticipantQuizController;
use App\Controllers\PublicParticipantPlayController;
use App\Controllers\PublicParticipantSubmitController;
use App\Controllers\PublicMonitorController;
use App\Controllers\PublicMonitorPageController;
use App\Core\AttemptRepository;
use App\Core\AttemptResponseRepository;
use App\Controllers\ParticipantQuizController;
use App\Controllers\ParticipantResultController;
use App\Core\CampaignBatchRepository;
use App\Core\CampaignRepository;
use App\Core\Config;
use App\Core\Database;
use App\Core\ParticipantRepository;
use App\Core\ParticipantQuizDeliveryService;
use App\Core\ParticipantStartService;
use App\Core\MonitorReadRepository;
use App\Core\MonitorService;
use App\Core\ProgramProvider;
use App\Core\QuizProvider;
use App\Core\QuizVersionRepository;
use App\Core\QuizVersionProgramRepository;
use App\Core\Request;
use App\Core\Response;
use App\Core\ResultPresentationFixtureProvider;
use App\Core\ResultRepository;
use App\Core\ResultScoreRepository;
use App\Core\ResultTiedProgramRepository;
use App\Core\Router;
use App\Core\ScoringEngine;
use App\Core\SmartLinkRepository;
use App\Core\SmartLinkService;
use App\Core\SubmissionService;
use App\Core\UuidV4Generator;
use App\Core\VisitorIdentityCookie;

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
    $database = new Database($config);
    $publicCampaignEntry = new PublicCampaignEntryController(
        $config,
        new SmartLinkService(
            new SmartLinkRepository($database),
            new CampaignRepository($database),
            new CampaignBatchRepository($database),
        ),
    );
    $publicParticipantStart = new PublicParticipantStartController(
        new SmartLinkService(
            new SmartLinkRepository($database),
            new CampaignRepository($database),
            new CampaignBatchRepository($database),
        ),
        new ParticipantStartService(
            $database,
            new AttemptRepository($database),
            new ParticipantRepository($database),
            new CampaignRepository($database),
            new CampaignBatchRepository($database),
            new QuizVersionRepository($database),
            new UuidV4Generator(),
        ),
        new VisitorIdentityCookie(new UuidV4Generator()),
    );
    $publicParticipantQuiz = new PublicParticipantQuizController(
        new ParticipantQuizDeliveryService(
            new AttemptRepository($database),
            new ParticipantRepository($database),
            new CampaignRepository($database),
            new CampaignBatchRepository($database),
            new QuizVersionRepository($database),
            new VisitorIdentityCookie(new UuidV4Generator()),
        ),
        new VisitorIdentityCookie(new UuidV4Generator()),
    );
    $publicParticipantPlay = new PublicParticipantPlayController(
        $config,
        new SmartLinkService(
            new SmartLinkRepository($database),
            new CampaignRepository($database),
            new CampaignBatchRepository($database),
        ),
    );
    $publicParticipantSubmit = new PublicParticipantSubmitController(
        new AttemptRepository($database),
        new SubmissionService($database, new AttemptRepository($database), new AttemptResponseRepository($database), new ResultRepository($database), new ResultScoreRepository($database), new ResultTiedProgramRepository($database), new ParticipantRepository($database), new CampaignRepository($database), new CampaignBatchRepository($database), new QuizVersionRepository($database), new QuizVersionProgramRepository($database), new ScoringEngine()),
        new VisitorIdentityCookie(new UuidV4Generator()),
    );
    $publicMonitor = new PublicMonitorController(
        new MonitorService(
            new SmartLinkService(
                new SmartLinkRepository($database),
                new CampaignRepository($database),
                new CampaignBatchRepository($database),
            ),
            new MonitorReadRepository($database),
        ),
    );
    $publicMonitorPage = new PublicMonitorPageController(
        $config,
        new SmartLinkService(
            new SmartLinkRepository($database),
            new CampaignRepository($database),
            new CampaignBatchRepository($database),
        ),
    );

    $router->get('/', static fn (Request $request): Response => $participantQuiz->index());
    $router->get('/result', static fn (Request $request): Response => $participantResult->preview('error'));
    $router->get('/result/dkv', static fn (Request $request): Response => $participantResult->preview('dkv'));
    $router->get('/result/mplb', static fn (Request $request): Response => $participantResult->preview('mplb'));
    $router->get('/result/pm', static fn (Request $request): Response => $participantResult->preview('pm'));
    $router->get('/result/tie', static fn (Request $request): Response => $participantResult->preview('tie'));
    $router->get('/result/error', static fn (Request $request): Response => $participantResult->preview('error'));
    $router->getPattern(
        '/go/{alias}',
        static fn (Request $request, array $parameters): Response => $publicCampaignEntry->entry($parameters['alias']),
    );
    $router->getPattern(
        '/play/{alias}',
        static fn (Request $request, array $parameters): Response => $publicParticipantPlay->play($parameters['alias']),
    );
    $router->postPattern(
        '/api/public/start/{alias}',
        static fn (Request $request, array $parameters): Response => $publicParticipantStart->start($request, $parameters['alias']),
    );
    $router->getPattern(
        '/api/public/start/{alias}',
        static fn (Request $request, array $parameters): Response => Response::json(['ok' => false, 'error' => 'method_not_allowed'], 405),
    );
    $router->getPattern(
        '/api/public/quiz/{attemptUuid}',
        static fn (Request $request, array $parameters): Response => $publicParticipantQuiz->quiz($request, $parameters['attemptUuid']),
    );
    $router->getPattern(
        '/api/public/monitor/{alias}',
        static fn (Request $request, array $parameters): Response => $publicMonitor->show($request, $parameters['alias']),
    );
    $router->getPattern(
        '/monitor/{alias}',
        static fn (Request $request, array $parameters): Response => $publicMonitorPage->show($parameters['alias']),
    );
    $router->postPattern(
        '/api/public/submit/{attemptUuid}',
        static fn (Request $request, array $parameters): Response => $publicParticipantSubmit->submit($request, $parameters['attemptUuid']),
    );
    $router->get('/health', static fn (Request $request): Response => Response::json([
        'status' => 'ok',
        'app' => $config->string('APP_NAME'),
        'environment' => $config->string('APP_ENV'),
        'php' => ['supported' => true],
    ]));

    return $router;
};
