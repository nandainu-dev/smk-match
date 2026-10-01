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
            <h3>Riwayat batch</h3>
            <ul class="admin-history-list">
                <?php foreach ($batches as $batch): ?>
                    <li>Batch <?= (int) $batch->batchNumber ?><?= $batch->label === null ? '' : ' — ' . $escape($batch->label) ?> · <?= $escape($batch->status) ?> · mulai <?= $escape($batch->startedAt) ?><?= $batch->closedAt === null ? '' : ' · ditutup ' . $escape($batch->closedAt) ?></li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="/admin/campaigns/<?= (int) $selectedCampaign->id ?>/batches/reset">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <label class="admin-field"><span>Ketik RESET untuk mengonfirmasi batch baru</span><input name="confirmation" autocomplete="off" required></label>
                <button class="admin-button admin-button--danger" type="submit">Tutup batch aktif dan buat batch baru</button>
            </form>
        </section>

        <section class="admin-card">
            <h2>Smart QR</h2>
            <p>QR selalu menuju alias kampanye. Reset batch tidak mengubah identitas QR.</p>
            <?php if ($smartLinks === []): ?>
                <p>Belum ada Smart Link untuk kampanye ini.</p>
            <?php endif; ?>
            <?php foreach ($smartLinks as $link): ?>
                <article class="admin-qr-card" data-admin-qr-target="<?= $escape($link['target']) ?>">
                    <h3><?= $escape($link['name']) ?></h3>
                    <p><code><?= $escape($link['target']) ?></code> · <?= $link['is_active'] ? 'aktif' : 'nonaktif' ?></p>
                    <div class="admin-qr-preview" data-admin-qr-preview role="img" aria-label="QR <?= $escape($link['alias']) ?>"></div>
                    <button class="admin-button admin-button--quiet" type="button" data-admin-qr-download>Unduh SVG</button>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/admin.php';
