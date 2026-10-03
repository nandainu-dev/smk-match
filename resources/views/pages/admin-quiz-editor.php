<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$definition = $version->definition();
$programCodes = array_keys($definition->programs);
$questions = $definition->questions;

ob_start();
?>
<main class="admin-shell">
    <header class="admin-page-header">
        <div>
            <p class="admin-eyebrow"><?= $escape($quiz['name']) ?></p>
            <h1><?= $escape($version->name) ?></h1>
            <p>Versi <?= $version->versionNumber ?> · <span class="admin-status admin-status--<?= strtolower($escape($version->status)) ?>"><?= $escape($version->status) ?></span></p>
        </div>
        <div class="admin-actions">
            <?php if ($isEditable): ?>
                <form method="post" action="/admin/quizzes/<?= (int) $quiz['id'] ?>/versions/<?= $version->id ?>/publish" onsubmit="return confirm('Publikasikan quiz ini? Setelah dipublikasikan, versi ini siap digunakan Campaign, tetapi belum otomatis diaktifkan untuk Campaign.')">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <button class="admin-button" type="submit">PUBLISH</button>
                </form>
            <?php endif; ?>
            <a class="admin-button admin-button--quiet" href="/admin/quizzes">Kembali ke daftar</a>
        </div>
    </header>

    <?php if ($message !== null): ?>
        <p class="admin-notice" role="status"><?= $message ?></p>
    <?php endif; ?>

    <?php if (!$isEditable): ?>
        <section class="admin-card">
            <h2>Versi ini bersifat historis</h2>
            <p>Versi dipublikasikan, dibuang, atau sudah dipakai peserta tidak dapat diubah. Kloning versi ini untuk membuat draft baru.</p>
        </section>
    <?php else: ?>
        <form class="admin-editor" method="post" enctype="multipart/form-data" action="/admin/quizzes/<?= (int) $quiz['id'] ?>/versions/<?= $version->id ?>/save">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <section class="admin-card">
                <label class="admin-field">
                    <span>Nama versi</span>
                    <input name="name" required value="<?= $escape($version->name) ?>">
                </label>
                <p class="admin-hint">Tiga program versi ini dikunci dari snapshot: <?= $escape(implode(', ', $programCodes)) ?>. Pilih jurusan untuk setiap pilihan jawaban.</p>
            </section>

            <div id="admin-question-list">
            <?php foreach ($questions as $questionIndex => $question): ?>
                <section class="admin-card admin-question-card">
                    <div class="admin-card-heading">
                        <h2>Pertanyaan <?= $questionIndex + 1 ?></h2>
                        <button class="admin-button admin-button--danger" type="submit" name="questions[<?= $questionIndex ?>][delete]" value="1" formnovalidate onclick="return confirm('Hapus pertanyaan ini dari draft?')">Hapus pertanyaan</button>
                    </div>
                    <input type="hidden" name="questions[<?= $questionIndex ?>][source_id]" value="<?= $escape($question['id']) ?>">
                    <div class="admin-grid">
                        <label class="admin-field admin-grid-wide">
                            <span>Pertanyaan</span>
                            <textarea name="questions[<?= $questionIndex ?>][text]" required><?= $escape($question['text']) ?></textarea>
                        </label>
                        <label class="admin-field admin-grid-wide">
                            <span>Bantuan (opsional)</span>
                            <textarea name="questions[<?= $questionIndex ?>][help_text]"><?= $escape(is_string($question['help_text'] ?? null) ? $question['help_text'] : '') ?></textarea>
                        </label>
                        <label class="admin-field">
                            <span>Urutan</span>
                            <input type="number" min="0" name="questions[<?= $questionIndex ?>][order]" required value="<?= (int) $question['order'] ?>">
                        </label>
                        <label class="admin-field">
                            <span>Gambar JPEG, PNG, atau WebP</span>
                            <input type="file" name="question_image_<?= $questionIndex ?>" accept="image/jpeg,image/png,image/webp">
                        </label>
                    </div>
                    <?php if (is_string($question['image_path'] ?? null) && $question['image_path'] !== ''): ?>
                        <div class="admin-image-preview">
                            <img src="<?= $escape($question['image_path']) ?>" alt="Pratinjau gambar pertanyaan">
                            <label class="admin-check"><input type="checkbox" name="questions[<?= $questionIndex ?>][remove_image]" value="1"> Lepas referensi gambar</label>
                        </div>
                    <?php endif; ?>

                    <h3>Opsi jawaban</h3>
                    <div data-options>
                    <?php foreach (array_slice($question['options'], 0, 4) as $optionIndex => $option): ?>
                        <?php $weightsByProgram = []; foreach ($option['weights'] as $weight) { $weightsByProgram[$weight['program']] = (string) $weight['weight']; } ?>
                        <?php $optionLetter = chr(65 + $optionIndex); $primaryProgram = array_key_first($weightsByProgram) ?? ''; ?>
                        <fieldset class="admin-option-row">
                            <legend>Pilihan <?= $escape($optionLetter) ?></legend>
                            <input type="hidden" name="questions[<?= $questionIndex ?>][options][<?= $optionIndex ?>][source_id]" value="<?= $escape($option['id']) ?>">
                            <label class="admin-field admin-grid-wide"><span>Jawaban</span><input name="questions[<?= $questionIndex ?>][options][<?= $optionIndex ?>][text]" required value="<?= $escape($option['text']) ?>"></label>
                            <input type="hidden" name="questions[<?= $questionIndex ?>][options][<?= $optionIndex ?>][order]" value="<?= (int) $option['order'] ?>">
                            <label class="admin-field"><span>Jurusan</span><select name="questions[<?= $questionIndex ?>][options][<?= $optionIndex ?>][primary_program]" required><option value="">Pilih jurusan</option><?php foreach ($programCodes as $programCode): ?><option value="<?= $escape($programCode) ?>"<?= $primaryProgram === $programCode ? ' selected' : '' ?>><?= $escape($programCode) ?></option><?php endforeach; ?></select></label>
                        </fieldset>
                    <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            </div>
            <button id="admin-add-question" class="admin-button admin-button--quiet" type="button">+ Tambahkan pertanyaan</button>
            <p class="admin-hint">Setiap pertanyaan memiliki empat pilihan A–D. Tambahkan pertanyaan sebanyak yang diperlukan.</p>
            <button class="admin-button" type="submit">Simpan draft</button>
        </form>
        <template id="admin-question-template"><section class="admin-card admin-question-card"><div class="admin-card-heading"><h2>Pertanyaan <span data-question-number></span></h2><button class="admin-button admin-button--danger" type="button" data-delete-question>Hapus pertanyaan</button></div><div class="admin-grid"><label class="admin-field admin-grid-wide"><span>Pertanyaan</span><textarea data-name="text" required></textarea></label><label class="admin-field admin-grid-wide"><span>Bantuan (opsional)</span><textarea data-name="help_text"></textarea></label><label class="admin-field"><span>Urutan</span><input type="number" min="0" data-name="order" required></label></div><h3>Opsi jawaban</h3><div data-options></div></section></template>
        <script>window.SMK_MATCH_AUTHORING_PROGRAMS = <?= json_encode(array_values($programCodes), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
        <script src="/assets/js/admin-quiz-authoring.js" defer></script>
    <?php endif; ?>
</main>
<?php
$content = (string) ob_get_clean();
$adminActiveNav = 'quiz';
require SMK_MATCH_ROOT . '/resources/views/layouts/admin.php';
