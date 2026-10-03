<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

ob_start();
?>
<main class="admin-shell">
    <header class="admin-page-header">
        <div><p class="admin-eyebrow">Admin sekolah</p><h1>Riwayat peserta dan hasil</h1><p>Rentang tanggal menggunakan kalender Asia/Jakarta.</p></div>
        <a class="admin-button admin-button--quiet" href="/admin/campaigns">Kampanye</a>
    </header>
    <?php if ($message !== null): ?><p class="admin-error" role="alert"><?= $message ?></p><?php endif; ?>
    <section class="admin-card">
        <p class="admin-eyebrow">Filter</p>
        <h2>Pilih data riwayat</h2>
        <form method="get" action="/admin/history" class="admin-grid">
            <label class="admin-field"><span>Kampanye</span><select name="campaign_id"><option value="">Semua riwayat</option><?php foreach ($campaigns as $campaign): ?><option value="<?= (int) $campaign->id ?>"<?= $filters['campaign_id'] === $campaign->id ? ' selected' : '' ?>><?= $escape($campaign->name) ?></option><?php endforeach; ?></select></label>
            <label class="admin-field"><span>Batch</span><select name="batch_id"><option value="">Semua batch kampanye</option><?php foreach ($batches as $batch): ?><option value="<?= (int) $batch->id ?>"<?= $filters['batch_id'] === $batch->id ? ' selected' : '' ?>>Batch <?= (int) $batch->batchNumber ?></option><?php endforeach; ?></select></label>
            <label class="admin-field"><span>Dari</span><input type="date" name="from" value="<?= $filters['from'] === null ? '' : $escape($filters['from']) ?>"></label>
            <label class="admin-field"><span>Sampai</span><input type="date" name="to" value="<?= $filters['to'] === null ? '' : $escape($filters['to']) ?>"></label>
            <button class="admin-button" type="submit">Terapkan filter</button>
            <button class="admin-button admin-button--quiet" type="submit" formaction="/admin/history/export">Unduh XLSX</button>
        </form>
    </section>
    <section class="admin-card"><p class="admin-eyebrow">Ringkasan</p><h2>Ringkasan program historis</h2><ul class="admin-history-list"><?php foreach ($summary as $program): ?><li><strong><?= $escape($program['code']) ?> — <?= $escape($program['name']) ?></strong>: <?= (int) $program['dominant_count'] ?> dominan · <?= number_format($program['score_average_percentage'], 2) ?>%</li><?php endforeach; ?></ul></section>
    <section class="admin-card">
        <h2>Riwayat upaya</h2>
        <?php if ($rows === []): ?>
            <p>Belum ada upaya dalam filter ini.</p>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-history-table">
                    <thead><tr><th scope="col">No</th><th scope="col">Tanggal</th><th scope="col">Nama Peserta</th><th scope="col">Smp Dwiguna</th><th scope="col">Kelas</th><th scope="col">Nomor Telepon</th><th scope="col">Jurusan</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php
                            $outcome = $row['outcome'];
                            $programNames = $outcome === null
                                ? 'Belum selesai'
                                : ($outcome['kind'] === 'tie'
                                    ? implode(', ', array_map(static fn (array $program): string => $program['name'], $outcome['tied_programs']))
                                    : $outcome['dominant_program']['name']);
                            ?>
                            <tr><td><?= (int) $index + 1 ?></td><td><?= $escape($row['attempt_created_at']) ?></td><td><?= $escape($row['participant_name']) ?></td><td><?= $escape($row['origin_school'] ?? '') ?></td><td><?= $escape($row['class_name'] ?? '') ?></td><td><?= $escape($row['phone'] ?? '') ?></td><td><?= $escape($programNames) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php
$content = (string) ob_get_clean();
$adminActiveNav = 'history';
require SMK_MATCH_ROOT . '/resources/views/layouts/admin.php';
