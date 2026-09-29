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
    <section aria-label="Programs foundation"><h2>Programs Foundation</h2><p>Active programs: <?= count($programs) ?></p><?php foreach ($programs as $program): ?><article><strong><?= htmlspecialchars($program->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong> — <?= htmlspecialchars($program->personalityTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= htmlspecialchars($program->mascotLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)</article><?php endforeach; ?></section>
    <p>G4 Programs Foundation — Development Only</p>
    <p>G3 Branding Foundation — Development Only</p>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/app.php';
