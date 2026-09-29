<?php
declare(strict_types=1);

use App\Controllers\HomeController;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/** @return Router */
return static function (Config $config): Router {
    $router = new Router();
    $home = new HomeController($config);
    $router->get('/', static fn (Request $request): Response => $home->index());
    $router->get('/health', static fn (Request $request): Response => Response::json([
        'status' => 'ok',
        'app' => $config->string('APP_NAME'),
        'environment' => $config->string('APP_ENV'),
        'php' => ['supported' => true],
    ]));

    return $router;
};
