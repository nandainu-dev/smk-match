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
        <a class="admin-button admin-button--quiet" href="/admin/quizzes">Kembali ke daftar</a>
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
                <p class="admin-hint">Program versi ini dikunci dari snapshot: <?= $escape(implode(', ', $programCodes)) ?>.</p>
            </section>

            <?php foreach ($questions as $questionIndex => $question): ?>
                <section class="admin-card admin-question-card">
                    <div class="admin-card-heading">
                        <h2>Pertanyaan <?= $questionIndex + 1 ?></h2>
                        <label class="admin-check"><input type="checkbox" name="questions[<?= $questionIndex ?>][delete]" value="1"> Hapus pertanyaan</label>
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
                    <?php foreach ($question['options'] as $optionIndex => $option): ?>
                        <?php $weightLines = array_map(static fn (array $weight): string => $weight['program'] . '=' . $weight['weight'], $option['weights']); ?>
                        <fieldset class="admin-option-row">
                            <legend>Opsi <?= $optionIndex + 1 ?></legend>
                            <input type="hidden" name="questions[<?= $questionIndex ?>][options][<?= $optionIndex ?>][source_id]" value="<?= $escape($option['id']) ?>">
                            <label class="admin-field admin-grid-wide"><span>Teks opsi</span><input name="questions[<?= $questionIndex ?>][options][<?= $optionIndex ?>][text]" required value="<?= $escape($option['text']) ?>"></label>
                            <label class="admin-field"><span>Urutan</span><input type="number" min="0" name="questions[<?= $questionIndex ?>][options][<?= $optionIndex ?>][order]" required value="<?= (int) $option['order'] ?>"></label>
                            <label class="admin-field"><span>Bobot (satu per baris: KODE=angka)</span><textarea name="questions[<?= $questionIndex ?>][options][<?= $optionIndex ?>][weights]"><?= $escape(implode("\n", $weightLines)) ?></textarea></label>
                            <label class="admin-check"><input type="checkbox" name="questions[<?= $questionIndex ?>][options][<?= $optionIndex ?>][delete]" value="1"> Hapus opsi</label>
                        </fieldset>
                    <?php endforeach; ?>
                    <?php $newOptionIndex = count($question['options']); ?>
                    <fieldset class="admin-option-row admin-option-row--new">
                        <legend>Opsi tambahan</legend>
                        <label class="admin-field admin-grid-wide"><span>Teks opsi</span><input name="questions[<?= $questionIndex ?>][options][<?= $newOptionIndex ?>][text]"></label>
                        <label class="admin-field"><span>Urutan</span><input type="number" min="0" name="questions[<?= $questionIndex ?>][options][<?= $newOptionIndex ?>][order]" value="<?= ((int) $question['options'][count($question['options']) - 1]['order']) + 10 ?>"></label>
                        <label class="admin-field"><span>Bobot (KODE=angka)</span><textarea name="questions[<?= $questionIndex ?>][options][<?= $newOptionIndex ?>][weights]"></textarea></label>
                    </fieldset>
                </section>
            <?php endforeach; ?>
            <?php $newQuestionIndex = count($questions); ?>
            <section class="admin-card admin-question-card admin-question-card--new">
                <h2>Tambahkan pertanyaan</h2>
                <div class="admin-grid">
                    <label class="admin-field admin-grid-wide"><span>Pertanyaan</span><textarea name="questions[<?= $newQuestionIndex ?>][text]"></textarea></label>
                    <label class="admin-field admin-grid-wide"><span>Bantuan (opsional)</span><textarea name="questions[<?= $newQuestionIndex ?>][help_text]"></textarea></label>
                    <label class="admin-field"><span>Urutan</span><input type="number" min="0" name="questions[<?= $newQuestionIndex ?>][order]" value="<?= (count($questions) + 1) * 10 ?>"></label>
                    <label class="admin-field"><span>Gambar JPEG, PNG, atau WebP</span><input type="file" name="question_image_<?= $newQuestionIndex ?>" accept="image/jpeg,image/png,image/webp"></label>
                </div>
                <?php for ($optionIndex = 0; $optionIndex < 2; $optionIndex++): ?>
                    <fieldset class="admin-option-row admin-option-row--new">
                        <legend>Opsi baru <?= $optionIndex + 1 ?></legend>
                        <label class="admin-field admin-grid-wide"><span>Teks opsi</span><input name="questions[<?= $newQuestionIndex ?>][options][<?= $optionIndex ?>][text]"></label>
                        <label class="admin-field"><span>Urutan</span><input type="number" min="0" name="questions[<?= $newQuestionIndex ?>][options][<?= $optionIndex ?>][order]" value="<?= ($optionIndex + 1) * 10 ?>"></label>
                        <label class="admin-field"><span>Bobot (KODE=angka)</span><textarea name="questions[<?= $newQuestionIndex ?>][options][<?= $optionIndex ?>][weights]"></textarea></label>
                    </fieldset>
                <?php endfor; ?>
            </section>
            <p class="admin-hint">Isi pertanyaan atau opsi tambahan hanya bila diperlukan. Setiap pertanyaan yang disimpan membutuhkan sedikitnya dua opsi valid.</p>
            <button class="admin-button" type="submit">Simpan draft</button>
        </form>
    <?php endif; ?>
</main>
<?php
$content = (string) ob_get_clean();
require SMK_MATCH_ROOT . '/resources/views/layouts/admin.php';
