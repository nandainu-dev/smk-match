<?php
declare(strict_types=1);

namespace App\Core;

final class ResultPresentationFixtureProvider
{
    /** @var array<string, ResultPresentationProfile> */
    private array $profilesByCode = [];

    /**
     * These are display-only development fixtures. They are deliberately not
     * calculated from quiz answers, option weights, or ScoringEngine output.
     *
     * @var array<string, array<string, mixed>>
     */
    private const PREVIEWS = [
        'dkv' => [
            'dominant_program' => 'DKV',
            'is_tie' => false,
            'tied_programs' => [],
            'percentages' => ['DKV' => 54, 'PM' => 29, 'MPLB' => 17],
            'ranking' => [
                ['program' => 'DKV', 'percentage' => 54],
                ['program' => 'PM', 'percentage' => 29],
                ['program' => 'MPLB', 'percentage' => 17],
            ],
        ],
        'mplb' => [
            'dominant_program' => 'MPLB',
            'is_tie' => false,
            'tied_programs' => [],
            'percentages' => ['MPLB' => 54, 'PM' => 28, 'DKV' => 18],
            'ranking' => [
                ['program' => 'MPLB', 'percentage' => 54],
                ['program' => 'PM', 'percentage' => 28],
                ['program' => 'DKV', 'percentage' => 18],
            ],
        ],
        'pm' => [
            'dominant_program' => 'PM',
            'is_tie' => false,
            'tied_programs' => [],
            'percentages' => ['PM' => 55, 'DKV' => 27, 'MPLB' => 18],
            'ranking' => [
                ['program' => 'PM', 'percentage' => 55],
                ['program' => 'DKV', 'percentage' => 27],
                ['program' => 'MPLB', 'percentage' => 18],
            ],
        ],
        'tie' => [
            'dominant_program' => null,
            'is_tie' => true,
            'tied_programs' => ['DKV', 'MPLB'],
            'percentages' => ['DKV' => 41.5, 'MPLB' => 41.5, 'PM' => 17],
            'ranking' => [
                ['program' => 'DKV', 'percentage' => 41.5],
                ['program' => 'MPLB', 'percentage' => 41.5],
                ['program' => 'PM', 'percentage' => 17],
            ],
        ],
    ];

    /**
     * @var array<string, array{tagline: string, description: string, superpower: string, skills: list<string>, careers: list<string>, share_headline: string}>
     */
    private const CONTENT = [
        'DKV' => [
            'tagline' => 'Kamu suka mengolah ide visual menjadi karya yang mudah diingat.',
            'description' => 'Kamu nyaman mengeksplorasi warna, bentuk, dan cara menyampaikan pesan secara kreatif.',
            'superpower' => 'Mengubah ide menjadi visual yang menarik dan komunikatif.',
            'skills' => ['Ilustrasi digital', 'Brand identity', 'Tipografi', 'Desain layout', 'Fotografi'],
            'careers' => ['Graphic Designer', 'UI/UX Designer', 'Illustrator & Animator'],
            'share_headline' => 'Aku menemukan kecenderungan kreatifku di SMK Match!',
        ],
        'MPLB' => [
            'tagline' => 'Kamu senang membuat banyak hal berjalan rapi dan terarah.',
            'description' => 'Kamu teliti saat mengatur informasi, berkomunikasi, dan menyelesaikan kebutuhan bersama.',
            'superpower' => 'Menjaga alur kerja tetap tertata sambil memberi layanan yang perhatian.',
            'skills' => ['Administrasi digital', 'Komunikasi bisnis', 'Pengarsipan', 'Layanan pelanggan', 'Manajemen agenda'],
            'careers' => ['Corporate Administrator', 'Office Supervisor', 'Customer Relations Specialist'],
            'share_headline' => 'Aku menemukan kecenderungan terorganisirku di SMK Match!',
        ],
        'PM' => [
            'tagline' => 'Kamu peka melihat kebutuhan orang dan peluang untuk menghubungkan ide.',
            'description' => 'Kamu tertarik menyusun pesan, membangun relasi, dan mengenalkan sesuatu dengan percaya diri.',
            'superpower' => 'Membuat ide lebih dekat dengan orang yang membutuhkannya.',
            'skills' => ['Komunikasi pemasaran', 'Riset pelanggan', 'Presentasi produk', 'Konten digital', 'Negosiasi'],
            'careers' => ['Digital Marketer', 'Sales Executive & Public Relations', 'Social Media Specialist', 'E-Commerce Specialist'],
            'share_headline' => 'Aku menemukan kecenderungan komunikatifku di SMK Match!',
        ],
    ];

    public function __construct(ProgramProvider $programs)
    {
        foreach ($programs->active() as $program) {
            $content = self::CONTENT[$program->code] ?? $this->genericContent();

            $this->profilesByCode[$program->code] = new ResultPresentationProfile(
                $program->code,
                $program->name,
                $program->personalityTitle,
                ProgramProvider::assetPath($program->mascotPath),
                $program->colors['primary'] ?? '#334155',
                $program->colors['accent'] ?? '#94A3B8',
                $content['tagline'],
                $content['description'],
                $content['superpower'],
                $content['skills'],
                $content['careers'],
                $content['share_headline'],
            );
        }
    }

    /** @return array<string, mixed> */
    public function preview(string $name): array
    {
        if ($name === 'error' || !isset(self::PREVIEWS[$name])) {
            return $this->errorFixture();
        }

        /** @var array<string, mixed> $result */
        $result = self::PREVIEWS[$name];
        $primaryCode = $result['dominant_program'];
        $primaryProfile = is_string($primaryCode) ? ($this->profilesByCode[$primaryCode] ?? null) : null;

        if (is_string($primaryCode) && $primaryProfile === null) {
            return $this->errorFixture();
        }

        return [
            'status' => 'success',
            'participant' => ['display_name' => 'Sahabat SMK'],
            'result' => $result,
            'presentation' => [
                'primary_profile' => $primaryProfile?->toSafeArray(true),
                'profiles' => $this->safeProfiles(),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function safeProfiles(): array
    {
        $profiles = [];

        foreach ($this->profilesByCode as $code => $profile) {
            $profiles[$code] = $profile->toSafeArray();
        }

        return $profiles;
    }

    /**
     * @return array{tagline: string, description: string, superpower: string, skills: list<string>, careers: list<string>, share_headline: string}
     */
    private function genericContent(): array
    {
        return [
            'tagline' => 'Kamu dapat mengeksplorasi kekuatan dan minatmu lewat berbagai kegiatan belajar.',
            'description' => 'Setiap program memberi ruang untuk belajar, berlatih, dan menemukan cara berkarya yang sesuai untukmu.',
            'superpower' => 'Membuka rasa ingin tahu untuk mencoba dan berkembang.',
            'skills' => ['Komunikasi', 'Kolaborasi', 'Pemecahan masalah'],
            'careers' => ['Eksplorasi lebih lanjut bersama sekolah'],
            'share_headline' => 'Aku mencoba SMK Match!',
        ];
    }

    /** @return array<string, mixed> */
    private function errorFixture(): array
    {
        return [
            'status' => 'error',
            'participant' => ['display_name' => 'Sahabat SMK'],
            'result' => null,
            'presentation' => ['primary_profile' => null, 'profiles' => []],
            'error' => ['message' => 'Hasil belum dapat ditampilkan. Silakan coba lagi nanti.'],
        ];
    }
}
