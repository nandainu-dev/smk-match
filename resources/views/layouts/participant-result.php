<?php
$participantResultCssVersion = filemtime(SMK_MATCH_ROOT . '/public/assets/css/participant-result.css') ?: 1;
$participantResultJsVersion = filemtime(SMK_MATCH_ROOT . '/public/assets/js/participant-result.js') ?: 1;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#312E81">
    <title><?= $appName ?> — Hasil</title>
    <link rel="stylesheet" href="/assets/css/participant-result.css?v=<?= (int) $participantResultCssVersion ?>">
    <script src="/assets/js/participant-result.js?v=<?= (int) $participantResultJsVersion ?>" defer></script>
</head>
<body class="participant-result-page">
    <?= $content ?>
</body>
</html>
