<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\SchoolProfile;
use App\Core\SchoolProfileProvider;

function brandingExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$provider = new SchoolProfileProvider();
$profile = $provider->developmentDefault();
brandingExpect($profile->isDevelopmentDefault, 'Default profile must be marked development-only.');
brandingExpect($profile->logoLabel() === 'Logo fallback active', 'Missing logo fallback failed.');
brandingExpect(SchoolProfileProvider::color('#2D176F', '#000000') === '#2D176F', 'Valid color rejected.');
brandingExpect(SchoolProfileProvider::color('url(javascript:alert(1))', '#000000') === '#000000', 'Unsafe color accepted.');
$unsafe = new SchoolProfile('<script>', 'x', '<b>', null, null, [], true);
brandingExpect(htmlspecialchars($unsafe->displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') === '&lt;script&gt;', 'HTML escaping failed.');
echo "Branding tests passed.\n";
