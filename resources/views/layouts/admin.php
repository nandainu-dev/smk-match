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
        .admin-shell { display: grid; gap: 20px; margin: 28px auto; max-width: 1120px; width: 100%; }
        .admin-card { background: #fff; border: 1px solid #ded7f7; border-radius: 18px; box-shadow: 0 18px 45px rgba(45, 25, 103, .12); padding: 28px; width: 100%; }
        h1 { font-family: "Fredoka One", Arial, sans-serif; font-size: 30px; margin: 0 0 8px; }
        p { line-height: 1.5; }
        label { display: block; font-weight: 700; margin-top: 18px; }
        input, textarea, select { border: 1px solid #9f93cd; border-radius: 8px; box-sizing: border-box; font: inherit; margin-top: 6px; padding: 11px; width: 100%; }
        textarea { min-height: 88px; resize: vertical; }
        button, .admin-button { background: #5b35c8; border: 0; border-radius: 8px; color: #fff; cursor: pointer; display: inline-block; font: inherit; font-weight: 700; margin-top: 24px; padding: 12px 18px; text-align: center; text-decoration: none; }
        .admin-error { background: #fff0f0; border: 1px solid #d99191; border-radius: 8px; color: #751d1d; padding: 10px 12px; }
        .admin-page-header, .admin-card-heading, .admin-version-row, .admin-actions, .admin-version-meta { align-items: center; display: flex; gap: 12px; justify-content: space-between; }
        .admin-page-header h1, .admin-card-heading h2 { margin-bottom: 0; }
        .admin-eyebrow { color: #5b35c8; font-size: .8rem; font-weight: 700; letter-spacing: .08em; margin: 0; text-transform: uppercase; }
        .admin-notice { background: #e8f9ef; border: 1px solid #74b68d; border-radius: 10px; padding: 12px 15px; }
        .admin-version-list, .admin-editor { display: grid; gap: 14px; }
        .admin-version-row { border-top: 1px solid #ece8fa; flex-wrap: wrap; padding: 16px 0; }
        .admin-version-row span { color: #605a77; display: block; margin-top: 4px; }
        .admin-actions { flex-wrap: wrap; }
        .admin-actions form, .admin-page-header form { margin: 0; }
        .admin-actions button, .admin-actions .admin-button, .admin-page-header button { margin-top: 0; }
        .admin-button--quiet { background: #ece8fa; color: #311e78; }
        .admin-button--danger { background: #a9324b; }
        .admin-status, .admin-used { border-radius: 999px; display: inline-block; font-size: .76rem; font-weight: 700; padding: 4px 9px; }
        .admin-status--draft { background: #fff1c9; color: #715300; }
        .admin-status--published { background: #ddf7e5; color: #176537; }
        .admin-status--discarded { background: #eeeaf3; color: #5c566a; }
        .admin-used { background: #f6e7ff; color: #703a8e; }
        .admin-grid { display: grid; gap: 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .admin-grid-wide { grid-column: 1 / -1; }
        .admin-field { margin: 0; }
        .admin-field span { display: block; }
        .admin-check { font-size: .9rem; margin: 0; }
        .admin-check input { margin: 0 6px 0 0; padding: 0; width: auto; }
        .admin-option-row { border: 1px solid #ded7f7; border-radius: 10px; display: grid; gap: 12px; margin: 14px 0; padding: 14px; }
        .admin-option-row legend { font-weight: 700; padding: 0 4px; }
        .admin-option-row--new, .admin-question-card--new { background: #fbfaff; border-style: dashed; }
        .admin-image-preview { display: grid; gap: 10px; margin-top: 16px; }
        .admin-image-preview img { border-radius: 10px; max-height: 220px; max-width: 100%; object-fit: contain; }
        .admin-nav { display: flex; flex-wrap: wrap; gap: 10px; }
        .admin-nav a { color: #311e78; font-weight: 700; text-decoration: none; }
        .admin-history-list { display: grid; gap: 12px; list-style: none; margin: 0; padding: 0; }
        .admin-history-list li, .admin-history-list article { border-top: 1px solid #ece8fa; padding-top: 12px; }
        .admin-qr-card { border-top: 1px solid #ece8fa; margin-top: 18px; padding-top: 18px; }
        .admin-qr-preview { background: #fff; border: 1px solid #ded7f7; border-radius: 10px; margin-top: 12px; max-width: 240px; padding: 12px; }
        .admin-qr-preview svg { display: block; height: auto; max-width: 100%; }
        .admin-hint { color: #605a77; font-size: .92rem; }
        @media (max-width: 720px) { body { align-items: flex-start; padding: 14px; } .admin-page-header, .admin-card-heading, .admin-version-row { align-items: flex-start; flex-direction: column; } .admin-grid { grid-template-columns: 1fr; } .admin-grid-wide { grid-column: auto; } }
    </style>
</head>
<body>
<nav class="admin-nav" aria-label="Navigasi admin">
    <a href="/admin">Quiz</a>
    <a href="/admin/campaigns">Kampanye</a>
    <a href="/admin/history">Riwayat</a>
    <a href="/admin/program-media">Media program</a>
</nav>
<?= $content ?>
<?php foreach (($adminScripts ?? []) as $adminScript): ?>
    <?php if (is_string($adminScript) && preg_match('#^/assets/[A-Za-z0-9._/-]+$#', $adminScript) === 1): ?>
        <script src="<?= htmlspecialchars($adminScript, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" defer></script>
    <?php endif; ?>
<?php endforeach; ?>
</body>
</html>
