<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

ob_start();
?>
<main class="admin-shell">
    <header class="admin-page-header">
        <div>
            <p class="admin-eyebrow">Admin sekolah</p>
            <h1>Media presentasi program</h1>
            <p>Maskot saat ini hanya digunakan untuk snapshot versi kuis yang dibuat setelah perubahan.</p>
        </div>
        <a class="admin-button admin-button--quiet" href="/admin/quizzes">Kelola quiz</a>
    </header>

    <?php if ($message !== null): ?>
        <p class="admin-notice" role="status"><?= $message ?></p>
    <?php endif; ?>

    <?php foreach ($programs as $program): ?>
        <section class="admin-card admin-program-media-card">
            <div class="admin-card-heading">
                <div>
                    <p class="admin-eyebrow"><?= $escape($program['code']) ?></p>
                    <h2><?= $escape($program['name']) ?></h2>
                </div>
                <?php if ($program['mascot_path'] !== null): ?>
                    <img class="admin-program-mascot" src="<?= $escape($program['mascot_path']) ?>" alt="Maskot <?= $escape($program['name']) ?>">
                <?php endif; ?>
            </div>


            <form method="post" action="/admin/programs/<?= (int) $program['id'] ?>/presentation-content">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <label class="admin-field">
                    <span>Skills utama</span>
                    <textarea name="skills" maxlength="3000" placeholder="Satu skill per baris"><?= $escape(implode("\n", is_array($program['skills'] ?? null) ? $program['skills'] : [])) ?></textarea>
                </label>

                <label class="admin-field">
                    <span>Peluang karir masa depan</span>
                    <textarea name="careers" maxlength="3000" placeholder="Satu peluang karir per baris"><?= $escape(implode("\n", is_array($program['careers'] ?? null) ? $program['careers'] : [])) ?></textarea>
                </label>

                <p class="admin-hint">
                    Konten ini menjadi snapshot ketika draft baru dibuat dengan data program terbaru.
                    Versi dan batch yang sudah ada tidak berubah.
                </p>

                <button class="admin-button admin-button--quiet" type="submit">
                    Simpan skills dan karir
                </button>
            </form>

            <form method="post" enctype="multipart/form-data" action="/admin/programs/<?= (int) $program['id'] ?>/mascot">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <label class="admin-field">
                    <span><?= $program['mascot_path'] === null ? 'Unggah maskot' : 'Ganti maskot' ?></span>
                    <input type="file" name="mascot" accept="image/jpeg,image/png,image/webp" required>
                </label>
                <p class="admin-hint">JPEG, PNG, atau WebP; maksimal 5 MiB dan 4096 × 4096 px.</p>
                <button class="admin-button" type="submit">Simpan maskot baru</button>
            </form>

            <?php if ($program['mascot_path'] !== null): ?>
                <form method="post" action="/admin/programs/<?= (int) $program['id'] ?>/mascot/remove">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <button class="admin-button admin-button--danger" type="submit">Lepas referensi maskot</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/admin.php';
