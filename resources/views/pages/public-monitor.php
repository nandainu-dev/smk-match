<?php
declare(strict_types=1);

ob_start();
?>
<main
    id="monitor-shell"
    class="monitor-shell"
    data-monitor-alias="<?= $monitorAlias ?>"
    data-monitor-qr-target="<?= $smartQrTarget ?>"
>
    <header class="monitor-header">
        <a class="monitor-brand" href="/" aria-label="SMK Match">
            <img src="/assets/mascots/mascot-all.png" alt="" width="56" height="56">
            <span>
                <strong>SMK MATCH</strong>
                <small>LIVE SCHOOL EXPLORATION</small>
            </span>
        </a>
        <p class="monitor-shell-status">Monitor aktif</p>
    </header>

    <div class="monitor-layout">
        <section class="monitor-stage" aria-label="Slide monitor">
            <div class="monitor-slides">
                <section class="monitor-slide monitor-slide--overview is-active" data-monitor-slide="overview" aria-labelledby="monitor-overview-title">
                    <div class="monitor-slide__copy">
                        <div class="monitor-overview-topline">
                            <p class="monitor-kicker">SMK MATCH • LIVE QUIZ</p>
                            <p class="monitor-participant-counter"><strong data-monitor-completed-count>—</strong> peserta sudah bermain! 🎮</p>
                        </div>
                        <h1 id="monitor-overview-title">Jurusan apa yang <span>cocok</span> buat kamu?</h1>
                        <p data-monitor-overview-batch>Scan QR di samping kanan, jawab 5 pertanyaan seru, dan temukan karakter jurusan SMK-mu!</p>
                        <section class="monitor-program-roster" aria-label="Program aktif">
                            <p>Temukan mascot SMK favoritmu 💎</p>
                            <div class="monitor-program-roster__items" data-monitor-program-roster></div>
                        </section>
                        <dl class="monitor-summary" aria-label="Ringkasan batch aktif">
                            <div><dt>Mulai</dt><dd data-monitor-started-count>—</dd></div>
                            <div><dt>Selesai</dt><dd data-monitor-completed-count>—</dd></div>
                            <div><dt>Setara</dt><dd data-monitor-tie-count>—</dd></div>
                        </dl>
                        <section class="monitor-live-progress" aria-labelledby="monitor-live-progress-title">
                            <header>
                                <h2 id="monitor-live-progress-title">Persentase jurusan terfavorit <span>(Live)</span></h2>
                            </header>
                            <div class="monitor-program-metrics" data-monitor-program-metrics aria-label="Metrik program"></div>
                        </section>
                    </div>
                </section>

                <section class="monitor-slide monitor-slide--program" data-monitor-slide="program-1" data-monitor-program-slot="0" aria-labelledby="monitor-program-1-title" hidden>
                    <div class="monitor-program-portrait">
                        <div class="monitor-program-image-frame"><img class="monitor-hero" data-program-monitor-image alt="" hidden><span data-program-image-empty>Gambar monitor belum tersedia.</span></div>
                        <p class="monitor-program-tagline" data-program-title>PROGRAM</p>
                        <div class="monitor-program-name-card"><h2 id="monitor-program-1-title" data-program-name>Program 1</h2></div>
                    </div>
                    <div class="monitor-slide__copy">
                        <p data-program-description>Menyiapkan sorotan program dari snapshot kuis aktif.</p>
                        <dl class="monitor-program-summary" data-program-summary>
                            <div><dt>Dominan</dt><dd data-program-dominant-count>—</dd></div>
                            <div><dt>Rata-rata</dt><dd data-program-average-percentage>—</dd></div>
                        </dl>
                        <h3 class="monitor-program-section-title">⚡ Skills utama yang akan kamu kuasai</h3>
                        <div class="monitor-chip-list" data-program-skills></div>
                        <h3 class="monitor-program-section-title">🎯 Peluang karir masa depan</h3>
                        <div class="monitor-chip-list monitor-chip-list--careers" data-program-careers></div>
                        <p class="monitor-unavailable" data-program-unavailable hidden>Program ini tidak tersedia pada versi kuis aktif.</p>
                    </div>
                </section>

                <section class="monitor-slide monitor-slide--program" data-monitor-slide="program-2" data-monitor-program-slot="1" aria-labelledby="monitor-program-2-title" hidden>
                    <div class="monitor-program-portrait">
                        <div class="monitor-program-image-frame"><img class="monitor-hero" data-program-monitor-image alt="" hidden><span data-program-image-empty>Gambar monitor belum tersedia.</span></div>
                        <p class="monitor-program-tagline" data-program-title>PROGRAM</p>
                        <div class="monitor-program-name-card"><h2 id="monitor-program-2-title" data-program-name>Program 2</h2></div>
                    </div>
                    <div class="monitor-slide__copy">
                        <p data-program-description>Menyiapkan sorotan program dari snapshot kuis aktif.</p>
                        <dl class="monitor-program-summary" data-program-summary>
                            <div><dt>Dominan</dt><dd data-program-dominant-count>—</dd></div>
                            <div><dt>Rata-rata</dt><dd data-program-average-percentage>—</dd></div>
                        </dl>
                        <h3 class="monitor-program-section-title">⚡ Skills utama yang akan kamu kuasai</h3>
                        <div class="monitor-chip-list" data-program-skills></div>
                        <h3 class="monitor-program-section-title">🎯 Peluang karir masa depan</h3>
                        <div class="monitor-chip-list monitor-chip-list--careers" data-program-careers></div>
                        <p class="monitor-unavailable" data-program-unavailable hidden>Program ini tidak tersedia pada versi kuis aktif.</p>
                    </div>
                </section>

                <section class="monitor-slide monitor-slide--program" data-monitor-slide="program-3" data-monitor-program-slot="2" aria-labelledby="monitor-program-3-title" hidden>
                    <div class="monitor-program-portrait">
                        <div class="monitor-program-image-frame"><img class="monitor-hero" data-program-monitor-image alt="" hidden><span data-program-image-empty>Gambar monitor belum tersedia.</span></div>
                        <p class="monitor-program-tagline" data-program-title>PROGRAM</p>
                        <div class="monitor-program-name-card"><h2 id="monitor-program-3-title" data-program-name>Program 3</h2></div>
                    </div>
                    <div class="monitor-slide__copy">
                        <p data-program-description>Menyiapkan sorotan program dari snapshot kuis aktif.</p>
                        <dl class="monitor-program-summary" data-program-summary>
                            <div><dt>Dominan</dt><dd data-program-dominant-count>—</dd></div>
                            <div><dt>Rata-rata</dt><dd data-program-average-percentage>—</dd></div>
                        </dl>
                        <h3 class="monitor-program-section-title">⚡ Skills utama yang akan kamu kuasai</h3>
                        <div class="monitor-chip-list" data-program-skills></div>
                        <h3 class="monitor-program-section-title">🎯 Peluang karir masa depan</h3>
                        <div class="monitor-chip-list monitor-chip-list--careers" data-program-careers></div>
                        <p class="monitor-unavailable" data-program-unavailable hidden>Program ini tidak tersedia pada versi kuis aktif.</p>
                    </div>
                </section>
            </div>

            <footer class="monitor-footer" data-monitor-footer>
                <?php if ($footerLogoPath !== null): ?>
                    <img src="<?= $footerLogoPath ?>" alt="Logo sekolah">
                <?php else: ?>
                    <span class="monitor-footer__mark" aria-hidden="true">✦</span>
                <?php endif; ?>
                <p><?= $footerText ?? 'SMK MATCH • Student Potential Exploration' ?></p>
            </footer>
        </section>

        <aside class="monitor-sidebar" aria-label="Panel monitor">
            <section class="monitor-panel monitor-qr" aria-labelledby="monitor-qr-title">
                <p class="monitor-scan-pill">👉 Scan to play</p>
                <h2 id="monitor-qr-title" class="visually-hidden">Scan QR</h2>
                <div class="monitor-qr-code" data-monitor-qr-code role="img" aria-label="QR untuk membuka kuis"></div>
                <p data-monitor-qr-copy><?= $smartQrAvailable ? 'Scan QR, jawab soal, temukan jurusanmu!' : 'QR belum dibuat oleh admin.' ?></p>
            </section>

            <section class="monitor-panel monitor-activity" aria-labelledby="monitor-activity-title">
                <div class="monitor-panel__heading">
                    <h2 id="monitor-activity-title"><span aria-hidden="true">●</span> Live result</h2>
                    <p>Realtime feed</p>
                </div>
                <ol class="monitor-activity-list">
                    <li><span class="monitor-avatar">?</span><span>Aktivitas peserta akan tampil di sini.</span></li>
                </ol>
            </section>
        </aside>
    </div>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/monitor.php';
