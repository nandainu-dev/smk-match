<?php
ob_start();
?>
<main aria-labelledby="campaign-entry-title">
    <h1 id="campaign-entry-title">SMK Match</h1>
    <?php if ($isAvailable): ?>
        <h2>Campaign siap</h2>
        <p>Campaign ini sudah siap. Halaman permainan akan tersedia pada tahap berikutnya.</p>
    <?php else: ?>
        <h2>Halaman tidak ditemukan</h2>
        <p>Campaign yang diminta tidak tersedia.</p>
    <?php endif; ?>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/app.php';
