<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;

final class HomeController
{
    public function __construct(private Config $config)
    {
    }

    public function index(): Response
    {
        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $environment = htmlspecialchars($this->config->string('APP_ENV'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/home.php';

        return new Response((string) ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
