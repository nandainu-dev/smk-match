<?php
ob_start();
?>
<main id="monitor-shell" class="monitor-shell" data-monitor-alias="<?= $monitorAlias ?>">
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
                        <p>Ruang monitor ini menyiapkan tampilan ringkas untuk aktivitas dan arah minat sekolah.</p>
                    </div>
                    <img class="monitor-hero monitor-hero--overview" src="/assets/mascots/mascot-all.png" alt="Maskot SMK Match">
                </section>

                <section class="monitor-slide monitor-slide--dkv" data-monitor-slide="dkv" aria-labelledby="monitor-dkv-title" hidden>
                    <div class="monitor-slide__copy">
                        <p class="monitor-kicker">PROGRAM SPOTLIGHT</p>
                        <h2 id="monitor-dkv-title">DKV</h2>
                        <p>Ruang sorot untuk cerita, karya, dan energi kreatif yang sedang dijelajahi siswa.</p>
                    </div>
                    <img class="monitor-hero" src="/assets/mascots/dkv/dkv-hero.png" alt="Maskot DKV">
                </section>

                <section class="monitor-slide monitor-slide--mplb" data-monitor-slide="mplb" aria-labelledby="monitor-mplb-title" hidden>
                    <div class="monitor-slide__copy">
                        <p class="monitor-kicker">PROGRAM SPOTLIGHT</p>
                        <h2 id="monitor-mplb-title">MPLB</h2>
                        <p>Ruang sorot untuk ketelitian, koordinasi, dan pengalaman mengatur banyak hal.</p>
                    </div>
                    <img class="monitor-hero" src="/assets/mascots/mplb/mplb-hero.png" alt="Maskot MPLB">
                </section>

                <section class="monitor-slide monitor-slide--pm" data-monitor-slide="pm" aria-labelledby="monitor-pm-title" hidden>
                    <div class="monitor-slide__copy">
                        <p class="monitor-kicker">PROGRAM SPOTLIGHT</p>
                        <h2 id="monitor-pm-title">PM</h2>
                        <p>Ruang sorot untuk hubungan, ide, dan peluang yang dapat dikembangkan bersama.</p>
                    </div>
                    <img class="monitor-hero" src="/assets/mascots/pm/pm-hero.png" alt="Maskot PM">
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
            <section class="monitor-panel monitor-qr-placeholder" aria-labelledby="monitor-qr-title">
                <p class="monitor-panel__eyebrow">BAGIKAN RUANG INI</p>
                <h2 id="monitor-qr-title">QR monitor</h2>
                <div class="monitor-qr-art" aria-hidden="true"><span></span><span></span><span></span></div>
                <p>Area QR akan tersedia pada tahap berikutnya.</p>
            </section>

            <section class="monitor-panel monitor-activity" aria-labelledby="monitor-activity-title">
                <div class="monitor-panel__heading">
                    <div>
                        <p class="monitor-panel__eyebrow">AKTIVITAS TERBARU</p>
                        <h2 id="monitor-activity-title">Live activity</h2>
                    </div>
                    <span class="monitor-shell-badge">SHELL</span>
                </div>
                <ol class="monitor-activity-list">
                    <li><span class="monitor-avatar">?</span><span>Aktivitas peserta akan tampil di sini.</span></li>
                    <li><span class="monitor-avatar">?</span><span>Ringkasan hasil terbaru akan muncul di panel ini.</span></li>
                    <li><span class="monitor-avatar">?</span><span>Panel ini belum memuat data langsung.</span></li>
                </ol>
            </section>
        </aside>
    </div>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/monitor.php';
