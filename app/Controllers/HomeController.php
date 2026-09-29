<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Core\SchoolProfileProvider;

final class HomeController
{
    public function __construct(private Config $config, private SchoolProfileProvider $schoolProfiles)
    {
    }

    public function index(): Response
    {
        $appName = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $environment = htmlspecialchars($this->config->string('APP_ENV'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $school = $this->schoolProfiles->developmentDefault();
        $schoolName = htmlspecialchars($school->displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $schoolTagline = htmlspecialchars((string) $school->tagline, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logoLabel = htmlspecialchars($school->logoLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $primaryColor = SchoolProfileProvider::color($school->branding['primary'] ?? '', '#2D176F');
        $accentColor = SchoolProfileProvider::color($school->branding['accent'] ?? '', '#FF4F87');
        ob_start();
        require SMK_MATCH_ROOT . '/resources/views/pages/home.php';

        return new Response((string) ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
