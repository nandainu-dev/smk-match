<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\PhpVersion;
use App\Core\Request;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = Config::load(SMK_MATCH_ROOT);
date_default_timezone_set($config->string('APP_TIMEZONE'));

PhpVersion::assertSupported(80300);
$rejectedUnsupportedVersion = false;
try {
    PhpVersion::assertSupported(80299);
} catch (RuntimeException) {
    $rejectedUnsupportedVersion = true;
}
expect($rejectedUnsupportedVersion, 'Unsupported PHP version was accepted.');

expect($config->string('APP_NAME') === 'SMK Match', 'APP_NAME default did not load.');
expect(date_default_timezone_get() === 'Asia/Jakarta', 'Timezone did not resolve to Asia/Jakarta.');

$routes = require SMK_MATCH_ROOT . '/routes/web.php';
$router = $routes($config);
$home = $router->dispatch(new Request('GET', '/'));
expect($home->status === 200 && str_contains($home->body, 'G1 Bootstrap'), 'Home route failed.');

$health = $router->dispatch(new Request('GET', '/health'));
$payload = json_decode($health->body, true, 512, JSON_THROW_ON_ERROR);
expect($health->status === 200 && $payload['status'] === 'ok' && $payload['php']['supported'] === true, 'Health route failed.');
expect($router->dispatch(new Request('GET', '/missing'))->status === 404, 'Unknown route did not return 404.');
expect(!is_file(SMK_MATCH_ROOT . '/.env') || (bool) preg_match('/^\\.env$/m', (string) file_get_contents(SMK_MATCH_ROOT . '/.gitignore')), '.env is not ignored.');
expect(is_file(SMK_MATCH_ROOT . '/design-reference/quiz/core/01-landing.png'), 'G0 quiz reference is missing.');
expect(is_file(SMK_MATCH_ROOT . '/public/assets/mascots/mascot-all.png'), 'G0 mascot asset is missing.');

echo "Bootstrap tests passed.\n";
