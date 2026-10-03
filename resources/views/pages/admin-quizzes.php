<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

ob_start();
?>
<main class="admin-shell">
    <header class="admin-page-header">
        <div>
            <p class="admin-eyebrow">Admin sekolah</p>
            <h1>Quiz dan versi</h1>
            <p>Kelola draft tanpa mengubah versi yang sudah dipublikasikan atau dipakai peserta.</p>
        </div>
        <form method="post" action="/admin/logout">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <button class="admin-button admin-button--quiet" type="submit">Keluar</button>
        </form>
    </header>

    <?php if ($message !== null): ?>
        <p class="admin-notice" role="status"><?= $message ?></p>
    <?php endif; ?>

    <?php if ($quizzes === []): ?>
        <section class="admin-card">
            <h2>Belum ada quiz</h2>
            <p>Quiz untuk sekolah ini akan muncul di sini setelah dibuat melalui fondasi data yang tersedia.</p>
        </section>
    <?php endif; ?>

    <?php foreach ($quizzes as $quiz): ?>
        <section class="admin-card admin-quiz-list-card">
            <div class="admin-card-heading">
                <div>
                    <p class="admin-eyebrow">Quiz #<?= (int) $quiz['id'] ?></p>
                    <h2><?= $escape($quiz['name']) ?></h2>
                </div>
                <form method="post" action="/admin/quizzes/<?= (int) $quiz['id'] ?>/draft">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <fieldset class="admin-program-selection">
                        <legend>Pilih tepat 3 program aktif untuk versi baru</legend>
                        <?php foreach ($activePrograms as $programIndex => $program): ?>
                            <label class="admin-check">
                                <input
                                    type="checkbox"
                                    name="program_ids[]"
                                    value="<?= (int) $program['id'] ?>"
                                    <?= $programIndex < 3 ? 'checked' : '' ?>
                                >
                                <?= $escape($program['name']) ?> <span>(<?= $escape($program['code']) ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="admin-hint">Pilihan A/B/C/D dapat dipetakan berulang kali ke salah satu dari tiga program ini pada editor draft.</p>
                    <button class="admin-button" type="submit" <?= count($activePrograms) < 3 ? 'disabled' : '' ?>>Buat draft 3 program</button>
                </form>
            </div>

            <div class="admin-version-list">
                <?php foreach ($quiz['versions'] as $version): ?>
                    <?php $statusLabel = ucfirst($version['status']); ?>
                    <article class="admin-version-row">
                        <div>
                            <strong>Versi <?= (int) $version['version_number'] ?></strong>
                            <span><?= $escape($version['name']) ?></span>
                        </div>
                        <div class="admin-version-meta">
                            <span class="admin-status admin-status--<?= strtolower($escape($version['status'])) ?>"><?= $escape($statusLabel) ?></span>
                            <?php if ($version['is_used']): ?>
                                <span class="admin-used">Pernah dipakai</span>
                            <?php endif; ?>
                        </div>
                        <div class="admin-actions">
                            <?php if ($version['status'] === 'draft' && !$version['is_used']): ?>
                                <a class="admin-button admin-button--quiet" href="/admin/quizzes/<?= (int) $quiz['id'] ?>/versions/<?= (int) $version['id'] ?>/edit">Edit draft</a>
                                <form method="post" action="/admin/quizzes/<?= (int) $quiz['id'] ?>/versions/<?= (int) $version['id'] ?>/publish" onsubmit="return confirm('Publikasikan quiz ini? Setelah dipublikasikan, versi ini siap digunakan Campaign, tetapi belum otomatis diaktifkan untuk Campaign.')">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <button class="admin-button" type="submit">PUBLISH</button>
                                </form>
                                <form method="post" action="/admin/quizzes/<?= (int) $quiz['id'] ?>/versions/<?= (int) $version['id'] ?>/discard">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <button class="admin-button admin-button--danger" type="submit">Buang draft</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/admin/quizzes/<?= (int) $quiz['id'] ?>/versions/<?= (int) $version['id'] ?>/clone">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <button class="admin-button admin-button--quiet" type="submit">Kloning ke draft</button>
                                </form>
                                <form method="post" action="/admin/quizzes/<?= (int) $quiz['id'] ?>/versions/<?= (int) $version['id'] ?>/clone-current-presentations" onsubmit="return confirm('Buat draft dengan soal versi ini dan data program terbaru? Versi sumber tidak akan diubah.')">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <button class="admin-button admin-button--quiet" type="submit">Draft + data program terbaru</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/admin.php';
