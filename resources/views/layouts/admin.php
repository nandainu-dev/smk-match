<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $appName ?> — Admin</title>
    <style>
        @font-face { font-family: "Fredoka One"; src: url("/assets/fonts/FredokaOne-Regular.ttf") format("truetype"); font-display: swap; }
        :root { color: #1f1645; background: #f4f1ff; font-family: Arial, sans-serif; }
        body { align-items: center; display: flex; justify-content: center; margin: 0; min-height: 100vh; padding: 24px; }
        .admin-card { background: #fff; border: 1px solid #ded7f7; border-radius: 18px; box-shadow: 0 18px 45px rgba(45, 25, 103, .12); max-width: 420px; padding: 32px; width: 100%; }
        h1 { font-family: "Fredoka One", Arial, sans-serif; font-size: 30px; margin: 0 0 8px; }
        p { line-height: 1.5; }
        label { display: block; font-weight: 700; margin-top: 18px; }
        input { border: 1px solid #9f93cd; border-radius: 8px; box-sizing: border-box; font: inherit; margin-top: 6px; padding: 11px; width: 100%; }
        button { background: #5b35c8; border: 0; border-radius: 8px; color: #fff; cursor: pointer; font: inherit; font-weight: 700; margin-top: 24px; padding: 12px 18px; width: 100%; }
        .admin-error { background: #fff0f0; border: 1px solid #d99191; border-radius: 8px; color: #751d1d; padding: 10px 12px; }
    </style>
</head>
<body>
<?= $content ?>
</body>
</html>
