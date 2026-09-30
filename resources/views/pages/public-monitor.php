<?php
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
        <p class="monitor-shell-status">Monitor siap ditampilkan</p>
    </header>

    <div class="monitor-layout">
        <section class="monitor-stage" aria-label="Slide monitor">
            <div class="monitor-slides">
                <section class="monitor-slide is-active" data-monitor-slide="overview" aria-labelledby="monitor-overview-title">
                    <div class="monitor-slide__copy">
                        <p class="monitor-kicker">SMK MATCH LIVE</p>
                        <h1 id="monitor-overview-title">Jelajahi potensi siswa bersama.</h1>
                        <p data-monitor-overview-batch>Menyiapkan ringkasan batch aktif.</p>
                        <dl class="monitor-summary" aria-label="Ringkasan batch aktif">
                            <div><dt>Mulai</dt><dd data-monitor-started-count>—</dd></div>
                            <div><dt>Selesai</dt><dd data-monitor-completed-count>—</dd></div>
                            <div><dt>Setara</dt><dd data-monitor-tie-count>—</dd></div>
                        </dl>
                        <div class="monitor-program-metrics" data-monitor-program-metrics aria-label="Metrik program"></div>
                    </div>
                    <img class="monitor-hero monitor-hero--overview" src="/assets/mascots/mascot-all.png" alt="Maskot SMK Match">
                </section>

                <section class="monitor-slide monitor-slide--dkv" data-monitor-slide="dkv" data-monitor-program-code="DKV" aria-labelledby="monitor-dkv-title" hidden>
                    <div class="monitor-slide__copy">
                        <p class="monitor-kicker" data-program-title>PROGRAM SPOTLIGHT</p>
                        <h2 id="monitor-dkv-title" data-program-name>DKV</h2>
                        <p data-program-description>Menyiapkan sorotan program dari snapshot kuis aktif.</p>
                        <dl class="monitor-program-summary" data-program-summary>
                            <div><dt>Dominan</dt><dd data-program-dominant-count>—</dd></div>
                            <div><dt>Rata-rata</dt><dd data-program-average-percentage>—</dd></div>
                        </dl>
                        <p class="monitor-unavailable" data-program-unavailable hidden>Program ini tidak tersedia pada versi kuis aktif.</p>
                    </div>
                    <img class="monitor-hero" data-program-mascot src="/assets/mascots/dkv/dkv-hero.png" alt="Maskot DKV">
                </section>

                <section class="monitor-slide monitor-slide--mplb" data-monitor-slide="mplb" data-monitor-program-code="MPLB" aria-labelledby="monitor-mplb-title" hidden>
                    <div class="monitor-slide__copy">
                        <p class="monitor-kicker" data-program-title>PROGRAM SPOTLIGHT</p>
                        <h2 id="monitor-mplb-title" data-program-name>MPLB</h2>
                        <p data-program-description>Menyiapkan sorotan program dari snapshot kuis aktif.</p>
                        <dl class="monitor-program-summary" data-program-summary>
                            <div><dt>Dominan</dt><dd data-program-dominant-count>—</dd></div>
                            <div><dt>Rata-rata</dt><dd data-program-average-percentage>—</dd></div>
                        </dl>
                        <p class="monitor-unavailable" data-program-unavailable hidden>Program ini tidak tersedia pada versi kuis aktif.</p>
                    </div>
                    <img class="monitor-hero" data-program-mascot src="/assets/mascots/mplb/mplb-hero.png" alt="Maskot MPLB">
                </section>

                <section class="monitor-slide monitor-slide--pm" data-monitor-slide="pm" data-monitor-program-code="PM" aria-labelledby="monitor-pm-title" hidden>
                    <div class="monitor-slide__copy">
                        <p class="monitor-kicker" data-program-title>PROGRAM SPOTLIGHT</p>
                        <h2 id="monitor-pm-title" data-program-name>PM</h2>
                        <p data-program-description>Menyiapkan sorotan program dari snapshot kuis aktif.</p>
                        <dl class="monitor-program-summary" data-program-summary>
                            <div><dt>Dominan</dt><dd data-program-dominant-count>—</dd></div>
                            <div><dt>Rata-rata</dt><dd data-program-average-percentage>—</dd></div>
                        </dl>
                        <p class="monitor-unavailable" data-program-unavailable hidden>Program ini tidak tersedia pada versi kuis aktif.</p>
                    </div>
                    <img class="monitor-hero" data-program-mascot src="/assets/mascots/pm/pm-hero.png" alt="Maskot PM">
                </section>
            </div>

            <nav class="monitor-navigation" aria-label="Navigasi slide">
                <button type="button" class="monitor-nav-button" data-monitor-direction="previous" aria-label="Slide sebelumnya">←</button>
                <div class="monitor-indicators" role="tablist" aria-label="Pilih slide">
                    <button type="button" role="tab" class="monitor-indicator is-active" data-monitor-target="overview" aria-selected="true" aria-controls="monitor-overview-title">Overview</button>
                    <button type="button" role="tab" class="monitor-indicator" data-monitor-target="dkv" aria-selected="false" aria-controls="monitor-dkv-title">DKV</button>
                    <button type="button" role="tab" class="monitor-indicator" data-monitor-target="mplb" aria-selected="false" aria-controls="monitor-mplb-title">MPLB</button>
                    <button type="button" role="tab" class="monitor-indicator" data-monitor-target="pm" aria-selected="false" aria-controls="monitor-pm-title">PM</button>
                </div>
                <button type="button" class="monitor-nav-button" data-monitor-direction="next" aria-label="Slide berikutnya">→</button>
            </nav>
        </section>

        <aside class="monitor-sidebar" aria-label="Panel monitor">
            <section class="monitor-panel monitor-qr" aria-labelledby="monitor-qr-title">
                <p class="monitor-panel__eyebrow">MULAI KUIS</p>
                <h2 id="monitor-qr-title">Scan QR</h2>
                <div class="monitor-qr-code" data-monitor-qr-code role="img" aria-label="QR untuk membuka kuis"></div>
                <p data-monitor-qr-copy>Scan untuk membuka tautan kuis aktif.</p>
            </section>

            <section class="monitor-panel monitor-activity" aria-labelledby="monitor-activity-title">
                <div class="monitor-panel__heading">
                    <div>
                        <p class="monitor-panel__eyebrow">AKTIVITAS TERBARU</p>
                        <h2 id="monitor-activity-title">Live activity</h2>
                    </div>
                    <span class="monitor-shell-badge" data-monitor-live-badge>LIVE</span>
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
