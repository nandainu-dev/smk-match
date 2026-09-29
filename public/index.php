<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit('SMK Match requires PHP 8.3 or later.');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\ErrorHandler;
use App\Core\PhpVersion;
use App\Core\Request;

PhpVersion::assertSupported();
$config = Config::load(SMK_MATCH_ROOT);
date_default_timezone_set($config->string('APP_TIMEZONE'));
ErrorHandler::register($config->debug());

$routes = require SMK_MATCH_ROOT . '/routes/web.php';
$router = $routes($config);
$router->dispatch(Request::fromGlobals())->send();
