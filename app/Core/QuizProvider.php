<?php
declare(strict_types=1);

namespace App\Core;

final class QuizProvider
{
    public function __construct(private ProgramProvider $programs) {}

    public function development(): QuizDefinition
    {
        $programContext = [];
        foreach ($this->programs->active() as $program) $programContext[$program->code] = true;
        $prompts = ['Saat bekerja dalam tim, kamu paling nyaman...','Ketika mendapat tugas sekolah, kamu biasanya...','Kegiatan yang paling menarik bagimu adalah...','Saat melihat masalah, kamu cenderung...','Hal yang ingin kamu pelajari lebih jauh adalah...'];
        $labels = ['Mengembangkan ide baru','Merapikan langkah kerja','Mengajak orang berdiskusi','Menyusun rencana tindakan'];
        $patterns = [['DKV','MPLB'],['MPLB','PM'],['PM','DKV'],['MPLB','DKV']];
        $questions = [];
        foreach ($prompts as $questionIndex => $prompt) {
            $options = [];
            foreach ($labels as $optionIndex => $label) {
                $codes = $patterns[($questionIndex + $optionIndex) % count($patterns)];
                $options[] = ['id' => 'q'.($questionIndex + 1).'o'.($optionIndex + 1), 'text' => $label, 'order' => $optionIndex + 1, 'weights' => [['program' => $codes[0], 'weight' => 3], ['program' => $codes[1], 'weight' => 1]]];
            }
            $questions[] = ['id' => 'q'.($questionIndex + 1), 'text' => $prompt, 'order' => $questionIndex + 1, 'options' => $options];
        }
        return new QuizDefinition('SMK Match Development Quiz', 1, $programContext, $questions);
    }
}
