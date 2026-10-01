<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$rate = static fn (?float $value): string => $value === null ? '—' : number_format($value * 100, 2) . '%';

ob_start();
?>
<main class="admin-shell">
    <header class="admin-page-header">
        <div><p class="admin-eyebrow">Admin sekolah</p><h1>Analitik historis</h1><p>Ringkasan agregat menggunakan kalender Asia/Jakarta dan data hasil yang tersimpan.</p></div>
        <a class="admin-button admin-button--quiet" href="/admin/history">Riwayat</a>
    </header>
    <?php if ($message !== null): ?><p class="admin-error" role="alert"><?= $message ?></p><?php endif; ?>
    <section class="admin-card">
        <form method="get" action="/admin/analytics" class="admin-grid">
            <label class="admin-field"><span>Kampanye</span><select name="campaign_id"><option value="">Semua kampanye</option><?php foreach ($campaigns as $campaign): ?><option value="<?= (int) $campaign->id ?>"<?= $filters['campaign_id'] === $campaign->id ? ' selected' : '' ?>><?= $escape($campaign->name) ?></option><?php endforeach; ?></select></label>
            <label class="admin-field"><span>Batch</span><select name="batch_id"><option value="">Semua batch kampanye</option><?php foreach ($batches as $batch): ?><option value="<?= (int) $batch->id ?>"<?= $filters['batch_id'] === $batch->id ? ' selected' : '' ?>>Batch <?= (int) $batch->batchNumber ?></option><?php endforeach; ?></select></label>
            <label class="admin-field"><span>Dari</span><input type="date" name="date_from" value="<?= $filters['date_from'] === null ? '' : $escape((string) $filters['date_from']) ?>"></label>
            <label class="admin-field"><span>Sampai</span><input type="date" name="date_to" value="<?= $filters['date_to'] === null ? '' : $escape((string) $filters['date_to']) ?>"></label>
            <button class="admin-button" type="submit">Terapkan filter</button>
        </form>
    </section>
    <section class="admin-card"><h2>Ringkasan</h2><div class="admin-grid"><p><strong><?= (int) ($summary['started_attempt_count'] ?? 0) ?></strong><br>Upaya dimulai</p><p><strong><?= (int) ($summary['completed_result_count'] ?? 0) ?></strong><br>Hasil selesai</p><p><strong><?= (int) ($summary['decisive_result_count'] ?? 0) ?></strong><br>Hasil tegas</p><p><strong><?= (int) ($summary['tie_count'] ?? 0) ?></strong><br>Hasil setara · <?= $rate($summary['tie_rate']['rate'] ?? null) ?></p></div></section>
    <section class="admin-card"><h2>Tren batch</h2><?php if ($batchTrends === []): ?><p>Belum ada batch dalam lingkup ini.</p><?php endif; ?><ul class="admin-history-list"><?php foreach ($batchTrends as $trend): ?><li><strong>Kampanye #<?= (int) $trend['campaign_id'] ?> · Batch <?= (int) $trend['batch_number'] ?></strong> <?= $trend['batch_label'] === null ? '' : '— ' . $escape((string) $trend['batch_label']) ?><span><?= (int) $trend['started_attempt_count'] ?> dimulai · <?= (int) $trend['completed_result_count'] ?> selesai · <?= (int) $trend['decisive_result_count'] ?> tegas · <?= (int) $trend['tie_count'] ?> setara (<?= $rate($trend['tie_rate']['rate']) ?>)</span></li><?php endforeach; ?></ul></section>
    <section class="admin-card"><h2>Tren kampanye</h2><ul class="admin-history-list"><?php foreach ($campaignTrends as $trend): ?><li><strong>Kampanye #<?= (int) $trend['campaign_id'] ?></strong><span><?= (int) $trend['started_attempt_count'] ?> dimulai · <?= (int) $trend['completed_result_count'] ?> selesai · <?= (int) $trend['tie_count'] ?> setara (<?= $rate($trend['tie_rate']['rate']) ?>)</span></li><?php endforeach; ?></ul></section>
    <section class="admin-card"><h2>Rata-rata skor program</h2><p class="admin-hint">Cohort versi berbeda dipisahkan; kode program yang sama tidak otomatis dibandingkan.</p><ul class="admin-history-list"><?php foreach ($programCohorts as $program): ?><li><strong>V<?= (int) $program['quiz_version_id'] ?> · <?= $escape((string) $program['program_code']) ?> — <?= $escape((string) $program['program_name']) ?></strong><span><?= (int) $program['dominant_count'] ?> dominan · rata-rata <?= $program['score_average_percentage'] === null ? '—' : number_format((float) $program['score_average_percentage'], 2) . '%' ?> (<?= (int) $program['score_average_denominator'] ?> hasil)</span></li><?php endforeach; ?></ul></section>
    <section class="admin-card"><h2>Perbandingan historis</h2><p class="admin-hint">Batch hanya dapat dibandingkan langsung di dalam cohort versi kuis yang sama.</p><ul class="admin-history-list"><?php foreach ($comparisonCohorts as $cohort): ?><li>Versi <?= (int) $cohort['quiz_version_id'] ?> · <?= (int) $cohort['program_count'] ?> program · batch <?= $escape(implode(', ', array_map('strval', $cohort['batch_ids']))) ?></li><?php endforeach; ?></ul></section>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/admin.php';
