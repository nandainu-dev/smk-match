<?php
declare(strict_types=1);

ob_start();
?>
<main id="participant-result" class="participant-result" aria-live="polite">
    <noscript>
        <section class="pr-noscript" aria-labelledby="pr-noscript-title">
            <p class="pr-eyebrow">SMK Match</p>
            <h1 id="pr-noscript-title">Hasil membutuhkan JavaScript</h1>
            <p>Aktifkan JavaScript untuk melihat presentasi hasilmu.</p>
        </section>
    </noscript>
</main>
<script id="participant-result-data" type="application/json"><?= $presentationJson ?></script>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/participant-result.php';
