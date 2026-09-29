<?php
ob_start();
?>
<main>
    <h1><?= $appName ?></h1>
    <p>Project Bootstrap</p>
    <p>Environment: <?= $environment ?></p>
    <p>PHP compatibility: OK</p>
    <section aria-label="School branding foundation" style="border: 2px solid <?= $primaryColor ?>; padding: 1rem; background: <?= $accentColor ?>;">
        <h2><?= $schoolName ?></h2>
        <p><?= $schoolTagline ?></p>
        <p><?= $logoLabel ?></p>
    </section>
    <p>G3 Branding Foundation — Development Only</p>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/app.php';
