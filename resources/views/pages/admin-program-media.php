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
            <div class="admin-program-media-layout">
                <div class="admin-media-preview" aria-label="Pratinjau maskot <?= $escape($program['name']) ?>">
                    <?php if ($program['mascot_path'] !== null): ?>
                        <img class="admin-program-mascot" src="<?= $escape($program['mascot_path']) ?>" alt="Maskot <?= $escape($program['name']) ?>">
                    <?php else: ?>
                        <p>Belum ada maskot</p>
                    <?php endif; ?>
                </div>
                <div>
                    <header class="admin-program-media-heading">
                        <p class="admin-eyebrow"><?= $escape($program['code']) ?></p>
                        <h2><?= $escape($program['name']) ?></h2>
                        <p class="admin-hint">Kelola nama, kode, dan media saat ini. Perubahan hanya dipakai oleh snapshot versi kuis baru.</p>
                    </header>

                    <form method="post" action="/admin/programs/<?= (int) $program['id'] ?>/presentation">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <div class="admin-grid">
                            <label class="admin-field">
                                <span>Nama program</span>
                                <input name="name" maxlength="255" required value="<?= $escape($program['name']) ?>">
                            </label>
                            <label class="admin-field">
                                <span>Kode ringkas</span>
                                <input name="code" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" required value="<?= $escape($program['code']) ?>">
                            </label>
                        </div>
                        <p class="admin-hint">Kode dipakai sebagai label snapshot baru; identitas program tetap ID yang stabil. Snapshot versi yang sudah ada tidak diubah.</p>
                        <button class="admin-button admin-button--quiet" type="submit">Simpan nama program</button>
                    </form>

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
                        <p class="admin-hint">Konten ini menjadi snapshot hanya ketika draft baru dibuat, lalu dipublikasikan dan diaktifkan. Versi dan batch yang sudah ada tidak berubah.</p>
                        <button class="admin-button admin-button--quiet" type="submit">Simpan skills dan karir</button>
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
                        <form class="admin-program-media-remove" method="post" action="/admin/programs/<?= (int) $program['id'] ?>/mascot/remove">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <button class="admin-button admin-button--danger" type="submit">Lepas referensi maskot</button>
                        </form>
                    <?php endif; ?>

                    <section class="admin-program-role-media" aria-label="Media terpisah <?= $escape($program['name']) ?>">
                        <h3>Media program</h3>
                        <p class="admin-hint">Setiap gambar digunakan untuk peran berbeda. Tidak ada penggantian otomatis antarperan.</p>
                        <?php foreach ([
                            'result' => ['Gambar Hasil', 'Dipakai pada hasil peserta', 'result_image_path'],
                            'share' => ['Gambar Bagikan', 'Dipakai pada kartu bagikan', 'share_image_path'],
                            'monitor' => ['Gambar Monitor', 'Dipakai pada showcase monitor', 'monitor_image_path'],
                        ] as $role => [$label, $usage, $field]): ?>
                            <?php $path = is_string($program[$field] ?? null) ? $program[$field] : null; ?>
                            <div class="admin-card admin-program-role-card">
                                <h4><?= $escape($label) ?></h4>
                                <p class="admin-hint"><?= $escape($usage) ?></p>
                                <?php if ($path !== null): ?>
                                    <img class="admin-program-mascot" src="<?= $escape($path) ?>" alt="Pratinjau <?= $escape($label) ?> <?= $escape($program['name']) ?>">
                                <?php else: ?>
                                    <p>Belum ada gambar.</p>
                                <?php endif; ?>
                                <form method="post" enctype="multipart/form-data" action="/admin/programs/<?= (int) $program['id'] ?>/media/<?= $escape($role) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <label class="admin-field"><span><?= $path === null ? 'Unggah gambar' : 'Ganti gambar' ?></span><input type="file" name="media" accept="image/jpeg,image/png,image/webp" required></label>
                                    <button class="admin-button" type="submit">Simpan <?= $escape($label) ?></button>
                                </form>
                                <?php if ($path !== null): ?>
                                    <form class="admin-program-media-remove" method="post" action="/admin/programs/<?= (int) $program['id'] ?>/media/<?= $escape($role) ?>/remove">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <button class="admin-button admin-button--danger" type="submit">Lepas referensi</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="admin-card" aria-labelledby="monitor-identity-title">
        <h2 id="monitor-identity-title">Identitas monitor</h2>
        <p class="admin-hint">Logo dan teks footer berlaku untuk monitor sekolah, bukan untuk program atau hasil peserta.</p>
        <form method="post" enctype="multipart/form-data" action="/admin/monitor-identity">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <?php if ($monitorIdentity['footer_logo_path'] !== null): ?>
                <img class="admin-program-mascot" src="<?= $escape($monitorIdentity['footer_logo_path']) ?>" alt="Pratinjau logo footer monitor">
            <?php else: ?>
                <p>Belum ada logo footer.</p>
            <?php endif; ?>
            <label class="admin-field"><span>Logo / gambar footer</span><input type="file" name="footer_logo" accept="image/jpeg,image/png,image/webp"></label>
            <label class="admin-field"><span>Teks footer</span><textarea name="footer_text" maxlength="2000"><?= $escape($monitorIdentity['footer_text'] ?? '') ?></textarea></label>
            <button class="admin-button" type="submit">Simpan identitas monitor</button>
        </form>
    </section>
</main>
<?php
$content = (string) ob_get_clean();
$adminActiveNav = 'media';
require SMK_MATCH_ROOT . '/resources/views/layouts/admin.php';
