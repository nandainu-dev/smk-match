<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

ob_start();
?>
<main class="admin-shell">
    <header class="admin-page-header">
        <div>
            <p class="admin-eyebrow">Admin sekolah</p>
            <h1>Kampanye dan batch</h1>
            <p>Reset menutup batch aktif dan membuat batch baru tanpa menghapus riwayat.</p>
        </div>
        <a class="admin-button admin-button--quiet" href="/admin/history">Riwayat hasil</a>
    </header>

    <?php if ($message !== null): ?>
        <p class="admin-error" role="alert"><?= $message ?></p>
    <?php endif; ?>

    <?php if ($campaigns === []): ?>
        <section class="admin-card"><h2>Belum ada kampanye</h2><p>Kampanye milik sekolah akan tampil di halaman ini.</p></section>
    <?php endif; ?>

    <section class="admin-card">
        <h2>Pilih kampanye</h2>
        <div class="admin-version-list">
            <?php foreach ($campaigns as $campaign): ?>
                <a class="admin-version-row" href="/admin/campaigns?campaign_id=<?= (int) $campaign->id ?>">
                    <span><strong><?= $escape($campaign->name) ?></strong><small><?= $escape($campaign->status) ?></small></span>
                    <span class="admin-button admin-button--quiet">Buka</span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($selectedCampaign !== null): ?>
        <section class="admin-card">
            <div class="admin-card-heading">
                <div><p class="admin-eyebrow">Kampanye aktif dipilih</p><h2><?= $escape($selectedCampaign->name) ?></h2></div>
                <span class="admin-status admin-status--<?= strtolower($escape($selectedCampaign->status)) ?>"><?= $escape($selectedCampaign->status) ?></span>
            </div>
            <p class="admin-hint">Kelola batch dan akses peserta tanpa mengubah riwayat hasil yang sudah tersimpan.</p>
        </section>

        <div class="admin-section-grid">
            <section class="admin-card">
                <p class="admin-eyebrow">Batch aktif</p>
                <?php $activeBatch = null; foreach ($batches as $batch) { if ($batch->status === 'active') { $activeBatch = $batch; break; } } ?>
                <?php if ($activeBatch === null): ?><p>Tidak ada batch aktif.</p><?php else: ?><h2>Batch <?= (int) $activeBatch->batchNumber ?></h2><p><?= $activeBatch->label === null ? 'Batch berjalan' : $escape($activeBatch->label) ?> · mulai <?= $escape($activeBatch->startedAt) ?></p><?php endif; ?>
            </section>
            <section class="admin-card admin-destructive">
                <p class="admin-eyebrow">Tindakan batch</p>
                <h2>Buat batch baru</h2>
                <p class="admin-hint">Tindakan ini menutup batch aktif lalu membuat batch kosong berikutnya. Riwayat peserta dan hasil tidak dihapus.</p>
                <form method="post" action="/admin/campaigns/<?= (int) $selectedCampaign->id ?>/batches/reset">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <label class="admin-field"><span>Ketik RESET untuk mengonfirmasi</span><input name="confirmation" autocomplete="off" required></label>
                    <button class="admin-button admin-button--danger" type="submit">Tutup batch aktif dan buat batch baru</button>
                </form>
            </section>
        </div>

        <section class="admin-card">
            <p class="admin-eyebrow">Batch terdahulu</p>
            <h2>Riwayat batch</h2>
            <ul class="admin-history-list">
                <?php foreach ($batches as $batch): ?>
                    <li>Batch <?= (int) $batch->batchNumber ?><?= $batch->label === null ? '' : ' — ' . $escape($batch->label) ?> · <?= $escape($batch->status) ?> · mulai <?= $escape($batch->startedAt) ?><?= $batch->closedAt === null ? '' : ' · ditutup ' . $escape($batch->closedAt) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="admin-card">
            <p class="admin-eyebrow">Versi kuis</p>
            <h2>Versi published untuk sesi berikutnya</h2>
            <p class="admin-hint">Mengaktifkan versi menutup batch aktif dan membuat batch baru. Riwayat batch sebelumnya tetap memakai versi asalnya.</p>
            <?php if ($publishedVersions === []): ?>
                <p>Tidak ada versi published untuk quiz kampanye ini.</p>
            <?php else: ?>
                <div class="admin-version-list">
                    <?php foreach ($publishedVersions as $version): ?>
                        <?php $isCurrent = $selectedCampaign->quizVersionId === $version['id']; ?>
                        <article class="admin-version-row">
                            <span><strong>Versi <?= (int) $version['version_number'] ?></strong><small><?= $escape($version['name']) ?> · <?= $escape($version['status']) ?><?= $isCurrent ? ' · sedang aktif' : '' ?></small></span>
                            <?php if ($isCurrent): ?>
                                <span class="admin-status admin-status--active">Sedang aktif</span>
                            <?php else: ?>
                                <form method="post" action="/admin/campaigns/<?= (int) $selectedCampaign->id ?>/quiz-versions/<?= (int) $version['id'] ?>/activate">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <label class="admin-field"><span>Ketik AKTIFKAN untuk mengonfirmasi</span><input name="confirmation" autocomplete="off" required></label>
                                    <button class="admin-button" type="submit">Jadikan versi ini aktif</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-card">
            <p class="admin-eyebrow">Smart Link & QR</p>
            <h2>Akses peserta dan Monitor</h2>
            <p>QR selalu menuju alias kampanye. URL QR tersimpan hanya saat Generate QR dan tidak berubah ketika konfigurasi Base URL berubah.</p>
            <?php if ($qrBaseUrlSuggestions !== []): ?>
                <datalist id="qr-base-url-suggestions">
                    <?php foreach ($qrBaseUrlSuggestions as $suggestion): ?>
                        <option value="<?= $escape($suggestion) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            <?php endif; ?>
            <?php if ($smartLinks === []): ?>
                <p>Belum ada Smart Link untuk kampanye ini.</p>
            <?php endif; ?>
            <?php foreach ($smartLinks as $link): ?>
                <?php $qrTarget = $link['qr_target_url']; ?>
                <article class="admin-qr-card"<?= $qrTarget === null ? '' : ' data-admin-qr-target="' . $escape($qrTarget) . '"' ?>>
                    <h3><?= $escape($link['name']) ?></h3>
                    <p class="admin-hint">Alias peserta: <code>/go/<?= $escape($link['alias']) ?></code> · <?= $link['is_active'] ? 'aktif' : 'nonaktif' ?></p>
                    <form method="post" action="/admin/campaigns/<?= (int) $selectedCampaign->id ?>/smart-links/<?= $escape($link['alias']) ?>/qr">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <label class="admin-field"><span>Alamat QR</span><input name="base_url" type="url" value="<?= $escape($qrBaseUrlSuggestions[0] ?? '') ?>"<?= $qrBaseUrlSuggestions === [] ? '' : ' list="qr-base-url-suggestions"' ?> placeholder="https://quiz.example.sch.id" required></label>
                        <button class="admin-button" type="submit">Generate QR</button>
                    </form>
                    <?php if ($qrTarget === null): ?>
                        <p class="admin-hint">QR belum dibuat. Pilih atau masukkan Base URL lalu tekan Generate QR.</p>
                    <?php else: ?>
                        <p class="admin-url">Link QR: <code><?= $escape($qrTarget) ?></code></p>
                        <div class="admin-inline-links">
                            <button class="admin-button admin-button--quiet" type="button" data-admin-copy-link>Salin Link</button>
                            <a class="admin-button admin-button--quiet" href="<?= $escape($qrTarget) ?>" target="_blank" rel="noopener">Buka Quiz</a>
                        </div>
                        <div class="admin-qr-preview" data-admin-qr-preview role="img" aria-label="QR <?= $escape($link['alias']) ?>"></div>
                        <button class="admin-button admin-button--quiet" type="button" data-admin-qr-download>Unduh SVG</button>
                    <?php endif; ?>
                    <section class="admin-qr-card">
                        <h4>Monitor</h4>
                        <p class="admin-hint">Menampilkan presentasi Monitor untuk kampanye aktif.</p>
                        <a class="admin-button" href="<?= $escape($link['monitor_target']) ?>" target="_blank" rel="noopener">Buka Monitor</a>
                    </section>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<?php
$content = (string) ob_get_clean();
$adminActiveNav = 'campaigns';
require SMK_MATCH_ROOT . '/resources/views/layouts/admin.php';
