<?php
ob_start();
?>
<main class="admin-card" aria-labelledby="admin-login-title">
    <h1 id="admin-login-title">Admin SMK Match</h1>
    <p>Masuk untuk mengelola demo sekolah ini.</p>
    <?php if ($errorMessage !== null): ?>
        <p class="admin-error" role="alert"><?= $errorMessage ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/login">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <label for="admin-email">Email</label>
        <input id="admin-email" name="email" type="email" autocomplete="username" required>
        <label for="admin-password">Kata sandi</label>
        <input id="admin-password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Masuk</button>
    </form>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/admin.php';
