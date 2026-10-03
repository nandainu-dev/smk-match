<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Config;
use App\Core\Program;
use App\Core\ProgramProvider;
use App\Core\Request;
use App\Core\ResultPresentationFixtureProvider;

/** @return array<string, mixed> */
function resultPayload(string $body): array
{
    $matched = preg_match(
        '#<script id="participant-result-data" type="application/json">(.*?)</script>#s',
        $body,
        $matches,
    );
    expect($matched === 1, 'Participant result payload script is missing.');

    $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    expect(is_array($payload), 'Participant result payload is not an object.');

    return $payload;
}

/** @param array<string, mixed> $payload */
function expectSafeResultPayload(array $payload): void
{
    $serialized = json_encode($payload, JSON_THROW_ON_ERROR);

    foreach (['raw_scores', 'weights', 'answers', 'questions', 'quiz_definition', 'origin_school', 'whatsapp', 'class', 'dkv_score', 'mplb_score', 'pm_score'] as $forbiddenField) {
        expect(!str_contains($serialized, '"' . $forbiddenField . '"'), "Result payload exposes {$forbiddenField}.");
    }

    expect(isset($payload['participant']['display_name']), 'Display name is missing from result payload.');
    expect(!isset($payload['participant']['phone']), 'Participant contact data must not be exposed.');
}

$config = Config::load(SMK_MATCH_ROOT);
$routes = require SMK_MATCH_ROOT . '/routes/web.php';
$router = $routes($config);

$home = $router->dispatch(new Request('GET', '/'));
expect($home->status === 200, 'Home route changed during G9.');

$health = $router->dispatch(new Request('GET', '/health'));
$healthPayload = json_decode($health->body, true, 512, JSON_THROW_ON_ERROR);
expect($health->status === 200 && $healthPayload['status'] === 'ok', 'Health route changed during G9.');

foreach (['dkv', 'mplb', 'pm', 'tie', 'error'] as $preview) {
    $response = $router->dispatch(new Request('GET', '/result/' . $preview));
    expect($response->status === 200, "Result preview {$preview} did not respond with 200.");
    expect(str_contains($response->body, 'participant-result.css?v='), "Result preview {$preview} does not cache-version its stylesheet.");
    expect(str_contains($response->body, 'participant-result.js?v='), "Result preview {$preview} does not cache-version its script.");
    expect(str_contains($response->body, 'FredokaOne-Regular.ttf') === false, 'Font asset should be declared in local CSS, not duplicated in HTML.');

    $payload = resultPayload($response->body);
    expectSafeResultPayload($payload);

    if (in_array($preview, ['dkv', 'mplb', 'pm'], true)) {
        expect(isset($payload['presentation']['primary_profile']['mascot_path']), "{$preview} result mascot is missing.");
    }
}

$defaultResult = $router->dispatch(new Request('GET', '/result'));
expect($defaultResult->status === 200, 'Safe default result route did not respond with 200.');
expect(resultPayload($defaultResult->body)['status'] === 'error', 'Safe default result must not invent a participant result.');
expect($router->dispatch(new Request('GET', '/result/unknown'))->status === 404, 'Unknown result preview did not fail safely.');

$dkvPayload = resultPayload($router->dispatch(new Request('GET', '/result/dkv'))->body);
expect($dkvPayload['status'] === 'success', 'DKV preview did not provide a success payload.');
expect($dkvPayload['result']['dominant_program'] === 'DKV', 'DKV preview dominant program is invalid.');
expect($dkvPayload['result']['is_tie'] === false, 'DKV preview was incorrectly marked as a tie.');
expect($dkvPayload['presentation']['primary_profile']['code'] === 'DKV', 'DKV primary profile is missing.');
expect(is_array($dkvPayload['result']['ranking']) && count($dkvPayload['result']['ranking']) === 3, 'DKV ranking is incomplete.');
expect(isset($dkvPayload['result']['percentages']['DKV']), 'DKV percentage is missing.');
expect(isset($dkvPayload['presentation']['primary_profile']['mascot_path']), 'Normal result mascot is missing.');

$tieResponse = $router->dispatch(new Request('GET', '/result/tie'));
$tiePayload = resultPayload($tieResponse->body);
expect($tiePayload['status'] === 'success', 'Tie preview did not provide a success payload.');
expect($tiePayload['result']['dominant_program'] === null, 'Tie preview selected a dominant program.');
expect($tiePayload['result']['is_tie'] === true, 'Tie preview was not marked as a tie.');
expect($tiePayload['result']['tied_programs'] === ['DKV', 'MPLB'], 'Tie program order is not deterministic.');
expect($tiePayload['presentation']['primary_profile'] === null, 'Tie preview exposes an individual primary profile.');
expect(!str_contains(json_encode($tiePayload, JSON_THROW_ON_ERROR), 'mascot_path'), 'Tie preview exposes an individual mascot.');

$errorPayload = resultPayload($router->dispatch(new Request('GET', '/result/error'))->body);
expect($errorPayload['status'] === 'error', 'Error preview does not use the error state.');
expect($errorPayload['result'] === null, 'Error preview must not expose a result object.');
expect(isset($errorPayload['error']['message']), 'Error preview lacks a safe user message.');
expect(!str_contains(json_encode($errorPayload, JSON_THROW_ON_ERROR), 'Exception'), 'Error preview exposes an internal error.');

$fourthProgram = new Program(
    'OTKP',
    'Otomatisasi dan Tata Kelola Perkantoran',
    'THE HELPFUL COORDINATOR',
    null,
    ['primary' => '#475569', 'accent' => '#94A3B8'],
    40,
    true,
);
$nProgramProvider = new ResultPresentationFixtureProvider(new ProgramProvider([$fourthProgram]));
$nProgramPayload = $nProgramProvider->preview('tie');
expect(isset($nProgramPayload['presentation']['profiles']['OTKP']), 'Result presentation does not support an additional active program.');

$styleSheet = (string) file_get_contents(SMK_MATCH_ROOT . '/public/assets/css/participant-result.css');
$script = (string) file_get_contents(SMK_MATCH_ROOT . '/public/assets/js/participant-result.js');
$shareResultStart = strpos($script, 'async function shareResult(');
$shareResultEnd = strpos($script, 'function shareImageFrame(', $shareResultStart === false ? 0 : $shareResultStart);
expect($shareResultStart !== false && $shareResultEnd !== false, 'Native-share handler boundaries are missing.');
$shareResultHandler = substr($script, $shareResultStart, $shareResultEnd - $shareResultStart);
expect(str_contains($styleSheet, '/assets/fonts/FredokaOne-Regular.ttf'), 'Result UI does not reuse the local Fredoka font.');
expect(preg_match('#https?://#', $styleSheet) !== 1, 'Result UI stylesheet loads a remote asset.');
expect(str_contains($styleSheet, '@media'), 'Result UI lacks responsive styling.');
expect(!str_contains($script, 'innerHTML'), 'Result UI must use safe DOM APIs.');
expect(!str_contains($script, 'raw_scores'), 'Client script must not process raw scores.');
expect(!str_contains($script, 'weights'), 'Client script must not process option weights.');
expect(!str_contains($script, 'answers'), 'Client script must not process participant answers.');
expect(str_contains($script, 'resultEntries()'), 'Client script does not render the generic ranking list.');
expect(str_contains($script, 'content.append(renderRanking(result));'), 'Participant result detail does not show the stored generic ranking.');
expect(str_contains($script, 'fill.style.setProperty("background-color", barColor, "important")'), 'Ranking fills do not receive a direct snapshot-color binding.');
expect(str_contains($script, 'programCode.style.setProperty("color", primaryColor, "important")'), 'Open result program code does not receive a direct snapshot-color binding.');
expect(str_contains($script, 'applyShareElementColors(programName, programCode, percentage, profile)'), 'Share card does not directly bind the snapshot color to its visible elements.');
expect(substr_count($script, 'participantName()') >= 5, 'Persisted participant name is not used throughout reveal, detail, share, and finish result screens.');
expect(!str_contains($script, '.sort('), 'Client script must not sort the server ranking.');
expect(str_contains($script, 'navigator.share'), 'Result UI does not feature-detect sharing.');
expect(str_contains($script, 'navigator.clipboard'), 'Result UI does not offer a copy-link fallback.');
expect(str_contains($script, 'document.execCommand("copy")'), 'Result UI lacks the clipboard fallback for restricted browsers.');
expect(str_contains($script, 'document.createElement("canvas")') && str_contains($script, '"image/jpeg"'), 'Result UI cannot create a JPEG share card.');
expect(str_contains($script, 'navigator.canShare') && str_contains($script, 'files: [file]'), 'Result UI does not use native file sharing when available.');
expect(str_contains($script, 'Jurusan yang cocok untuk anda :'), 'Share card does not include the approved result label.');
expect(str_contains($script, 'Deskripsi program belum tersedia.'), 'Result detail has no safe missing-description state.');
expect(str_contains($script, 'resultImagePath(profile)'), 'Result reveal/detail do not use the result-media role.');
expect(str_contains($script, 'shareImagePath(profile)'), 'Result share card does not use the share-media role.');
expect(!str_contains($script, 'monitor_image_path'), 'Result UI must not consume monitor media.');
expect(str_contains($styleSheet, '.pr-share-image-frame') && str_contains($styleSheet, 'object-fit: cover'), 'Share card image is not rendered as a covered rounded rectangle.');
expect(str_contains($styleSheet, 'background-color: var(--program-color);'), 'Ranking bars do not preserve their program snapshot colors.');
expect(str_contains($script, 'resolvedMonitorProgramColor(programProfile)'), 'Each result-progress row must use the same primary-color selection as Monitor.');
expect(!str_contains($script, 'programProfile?.primary_color || themeProfile?.primary_color'), 'Result progress must not fall back to the dominant color for another program row.');
expect(str_contains($script, 'applyShareCardColors(card, isTie ? null : profile);'), 'Share card does not receive its dominant snapshot color.');
expect(str_contains($script, 'const candidate = typeof profile?.primary_color === "string" ? profile.primary_color.trim() : "";'), 'Result UI does not resolve the monitor primary_color snapshot field.');
expect(str_contains($styleSheet, 'color: var(--share-program-color'), 'Share card program name does not use its snapshot primary color.');
expect(str_contains($styleSheet, 'background: var(--share-program-color);'), 'Share card percentage does not use its snapshot primary color.');
expect(str_contains($script, 'contrastTextColor(primary)'), 'Canvas JPEG percentage does not use a readable contrast color.');
expect(str_contains($script, 'window.isSecureContext !== true'), 'Native sharing is not restricted to secure contexts.');
expect(str_contains($script, 'Bagikan langsung belum tersedia pada koneksi HTTP lokal.'), 'HTTP local share feedback is missing.');
expect(str_contains($script, 'navigator.share({ files: [file], title: "Hasil SMK Match", text })'), 'Native file sharing is not implemented.');
expect(str_contains($script, 'await navigator.share({ title: "Hasil SMK Match", text });'), 'Text native-share fallback is missing.');
expect(str_contains($script, 'error?.name === "AbortError"'), 'Share cancellation is not handled.');
expect(!str_contains($shareResultHandler, 'downloadBlob('), 'Share action must never fall back to a JPG download.');
expect(str_contains($script, 'function saveShareCard(') && str_contains($script, 'downloadBlob(blob, shareCardFilename());'), 'Save card must remain the only JPG download action.');
expect(!preg_match('/\b(?:DKV|MPLB|PM)\b/', $script), 'Result client must not hardcode program identities.');
expect(str_contains($styleSheet, 'prefers-reduced-motion'), 'Result UI does not support reduced motion.');

$previewUrl = 'http://127.0.0.1:8080/result/dkv';
foreach (['Sahabat', 'whatsapp', 'school', 'class', '54'] as $sensitiveUrlValue) {
    expect(!str_contains($previewUrl, $sensitiveUrlValue), "Preview URL exposes {$sensitiveUrlValue}.");
}

echo "Participant result tests passed.\n";
