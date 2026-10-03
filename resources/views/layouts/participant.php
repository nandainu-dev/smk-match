<?php
$participantQuizCssVersion = filemtime(SMK_MATCH_ROOT . '/public/assets/css/participant-quiz.css') ?: 1;
$participantQuizJsVersion = filemtime(SMK_MATCH_ROOT . '/public/assets/js/participant-quiz.js') ?: 1;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2d176f">
    <title><?= $appName ?></title>
    <link rel="stylesheet" href="/assets/css/participant-quiz.css?v=<?= (int) $participantQuizCssVersion ?>">
</head>
<body class="participant-page">
<?= $content ?>
<script src="/assets/js/participant-quiz.js?v=<?= (int) $participantQuizJsVersion ?>" defer></script>
</body>
</html>
