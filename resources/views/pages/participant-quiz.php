<?php
ob_start();
?>
<!-- Project Bootstrap health marker -->
<main id="participant-quiz" class="participant-quiz" aria-live="polite">
    <noscript>
        <section class="pq-noscript" aria-label="JavaScript required">
            <h1>SMK Match membutuhkan JavaScript</h1>
            <p>Aktifkan JavaScript untuk memainkan kuis ini.</p>
        </section>
    </noscript>
</main>
<script id="participant-quiz-data" type="application/json"><?= $quizJson ?></script>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/participant.php';
