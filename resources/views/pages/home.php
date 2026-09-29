<?php
ob_start();
?>
<main>
    <h1><?= $appName ?></h1>
    <p>Project Bootstrap</p>
    <p>Environment: <?= $environment ?></p>
    <p>PHP compatibility: OK</p>
    <p>G1 Bootstrap — Development Only</p>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/app.php';
