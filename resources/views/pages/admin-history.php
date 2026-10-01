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
        <form method="get" action="/admin/history" class="admin-grid">
            <label class="admin-field"><span>Kampanye</span><select name="campaign_id"><option value="">Semua riwayat</option><?php foreach ($campaigns as $campaign): ?><option value="<?= (int) $campaign->id ?>"<?= $filters['campaign_id'] === $campaign->id ? ' selected' : '' ?>><?= $escape($campaign->name) ?></option><?php endforeach; ?></select></label>
            <label class="admin-field"><span>Batch</span><select name="batch_id"><option value="">Semua batch kampanye</option><?php foreach ($batches as $batch): ?><option value="<?= (int) $batch->id ?>"<?= $filters['batch_id'] === $batch->id ? ' selected' : '' ?>>Batch <?= (int) $batch->batchNumber ?></option><?php endforeach; ?></select></label>
            <label class="admin-field"><span>Dari</span><input type="date" name="from" value="<?= $filters['from'] === null ? '' : $escape($filters['from']) ?>"></label>
            <label class="admin-field"><span>Sampai</span><input type="date" name="to" value="<?= $filters['to'] === null ? '' : $escape($filters['to']) ?>"></label>
            <button class="admin-button" type="submit">Terapkan filter</button>
        </form>
    </section>
    <section class="admin-card"><h2>Ringkasan program historis</h2><ul class="admin-history-list"><?php foreach ($summary as $program): ?><li><strong><?= $escape($program['code']) ?> — <?= $escape($program['name']) ?></strong>: <?= (int) $program['dominant_count'] ?> dominan · <?= number_format($program['score_average_percentage'], 2) ?>%</li><?php endforeach; ?></ul></section>
    <section class="admin-card"><h2>Riwayat upaya</h2><?php if ($rows === []): ?><p>Belum ada upaya dalam filter ini.</p><?php endif; ?><div class="admin-history-list"><?php foreach ($rows as $row): ?><article class="admin-version-row"><div><strong><?= $escape($row['participant_name']) ?></strong><span><?= $escape($row['campaign_name']) ?><?= $row['batch_number'] === null ? '' : ' · Batch ' . (int) $row['batch_number'] ?> · <?= $escape($row['attempt_created_at']) ?></span></div><div><?php if ($row['outcome'] === null): ?>Belum selesai<?php elseif ($row['outcome']['kind'] === 'tie'): ?>Hasil setara: <?= $escape(implode(', ', array_map(static fn (array $program): string => $program['code'], $row['outcome']['tied_programs']))) ?><?php else: ?>Dominan: <?= $escape($row['outcome']['dominant_program']['code']) ?><?php endif; ?><?php if ($row['ranking'] !== []): ?><ol><?php foreach ($row['ranking'] as $rank): ?><li>#<?= (int) $rank['display_order'] ?> <?= $escape($rank['program']['code']) ?> — <?= number_format($rank['normalized_percentage'], 2) ?>%</li><?php endforeach; ?></ol><?php endif; ?></div></article><?php endforeach; ?></div></section>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/admin.php';
