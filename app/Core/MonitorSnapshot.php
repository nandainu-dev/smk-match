<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MonitorSnapshot
{
    /**
     * @param array{id: int, number: int, label: ?string, started_at: string} $batch
     * @param array{started_count: int, completed_count: int, tie_count: int} $summary
     * @param list<array<string, mixed>> $programs
     * @param list<array<string, mixed>> $recentActivity
     * @param array{footer_logo_path: ?string, footer_text: ?string} $footer
     * @param array{result_ids: list<int>, has_more: bool, next_page_after_result_id: ?int}|null $reconciliation
     */
    private function __construct(
        public readonly string $alias,
        public readonly array $batch,
        public readonly array $summary,
        public readonly array $programs,
        public readonly array $recentActivity,
        public readonly array $footer,
        public readonly ?array $reconciliation,
    ) {
        if (SmartLink::canonicalAlias($this->alias) !== $this->alias) {
            throw new \InvalidArgumentException('Monitor snapshot alias must be canonical.');
        }
    }

    /**
     * @param array{
     *     batch: array{id: int, campaign_id: int, batch_number: int, quiz_version_id: int, label: ?string, started_at: string},
     *     summary: array{started_count: int, completed_count: int, tie_count: int},
     *     programs: list<array<string, mixed>>,
     *     recent_events: list<array<string, mixed>>,
     *     reconciliation?: array{result_ids: list<int>, has_more: bool, next_page_after_result_id: ?int}
     * } $readModel
     */
    public static function fromReadModel(
        string $alias,
        array $readModel,
        array $footer = ['footer_logo_path' => null, 'footer_text' => null],
    ): self
    {
        $batch = $readModel['batch'];

        return new self(
            $alias,
            [
                'id' => $batch['id'],
                'number' => $batch['batch_number'],
                'label' => $batch['label'],
                'started_at' => $batch['started_at'],
            ],
            $readModel['summary'],
            array_map(self::publicProgramMetric(...), $readModel['programs']),
            array_map(self::publicEvent(...), $readModel['recent_events']),
            self::publicFooter($footer),
            array_key_exists('reconciliation', $readModel)
                ? self::publicReconciliation($readModel['reconciliation'])
                : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $snapshot = [
            'alias' => $this->alias,
            'batch' => $this->batch,
            'summary' => $this->summary,
            'programs' => $this->programs,
            'recent_activity' => $this->recentActivity,
            'footer' => $this->footer,
        ];

        if ($this->reconciliation !== null) {
            $snapshot['reconciliation'] = $this->reconciliation;
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $program @return array<string, mixed> */
    private static function publicProgramMetric(array $program): array
    {
        return [
            ...self::publicProgramPresentation($program),
            'dominant_count' => self::nonNegativeInt($program, 'dominant_count'),
            'score_average_percentage' => self::finiteFloat($program, 'score_average_percentage'),
        ];
    }

    /** @param array<string, mixed> $program @return array<string, mixed> */
    private static function publicProgramPresentation(array $program): array
    {
        return [
            'code' => self::string($program, 'code'),
            'name' => self::string($program, 'name'),
            'personality_title' => self::nullableString($program, 'personality_title'),
            'mascot_path' => self::nullableString($program, 'mascot_path'),
            'monitor_image_path' => self::nullableString($program, 'monitor_image_path'),
            'primary_color' => self::nullableString($program, 'primary_color'),
            'accent_color' => self::nullableString($program, 'accent_color'),
            'tagline' => self::nullableString($program, 'tagline'),
            'description' => self::nullableString($program, 'description'),
            'superpower' => self::nullableString($program, 'superpower'),
            'skills' => self::nullableString($program, 'skills'),
            'careers' => self::nullableString($program, 'careers'),
        ];
    }

    /** @param array<string, mixed> $footer @return array{footer_logo_path: ?string, footer_text: ?string} */
    private static function publicFooter(array $footer): array
    {
        $logoPath = self::nullableString($footer, 'footer_logo_path');
        if ($logoPath !== null && !ProgramMediaStorage::isCanonicalPublicPath($logoPath)) {
            throw new RuntimeException('Invalid monitor footer logo.');
        }

        return [
            'footer_logo_path' => $logoPath,
            'footer_text' => self::nullableString($footer, 'footer_text'),
        ];
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private static function publicEvent(array $event): array
    {
        $isTie = self::bool($event, 'is_tie');
        $dominantProgram = $event['dominant_program'] ?? null;
        $tiedPrograms = $event['tied_programs'] ?? null;

        if (!is_array($tiedPrograms) || !array_is_list($tiedPrograms)) {
            throw new RuntimeException('Invalid monitor event tie data.');
        }

        if ($isTie) {
            if ($dominantProgram !== null || count($tiedPrograms) < 2) {
                throw new RuntimeException('Invalid tied monitor event.');
            }

            $outcome = [
                'kind' => 'tie',
                'message_key' => 'monitor.tie',
                'dominant_program' => null,
                'tied_programs' => array_map(self::publicProgramPresentation(...), $tiedPrograms),
            ];
        } else {
            if (!is_array($dominantProgram) || $tiedPrograms !== []) {
                throw new RuntimeException('Invalid decisive monitor event.');
            }

            $outcome = [
                'kind' => 'decisive',
                'message_key' => 'monitor.decisive',
                'dominant_program' => self::publicProgramPresentation($dominantProgram),
                'tied_programs' => [],
            ];
        }

        $ranking = $event['ranking'] ?? null;
        if (!is_array($ranking) || !array_is_list($ranking)) {
            throw new RuntimeException('Invalid monitor ranking data.');
        }

        return [
            'result_id' => self::positiveInt($event, 'result_id'),
            'participant_name' => self::string($event, 'participant_name'),
            'submitted_at' => self::string($event, 'submitted_at'),
            'outcome' => $outcome,
            'ranking' => array_map(static function (mixed $row): array {
                if (!is_array($row)) {
                    throw new RuntimeException('Invalid monitor ranking row.');
                }

                return [
                    'display_order' => self::positiveInt($row, 'display_order'),
                    'normalized_percentage' => self::finiteFloat($row, 'normalized_percentage'),
                    'program' => self::publicProgramPresentation(self::arrayValue($row, 'program')),
                ];
            }, $ranking),
        ];
    }

    /** @param array<string, mixed> $page @return array{result_ids: list<int>, has_more: bool, next_page_after_result_id: ?int} */
    private static function publicReconciliation(mixed $page): array
    {
        if (!is_array($page)) {
            throw new RuntimeException('Invalid monitor reconciliation data.');
        }

        $resultIds = $page['result_ids'] ?? null;
        if (!is_array($resultIds) || !array_is_list($resultIds)) {
            throw new RuntimeException('Invalid monitor reconciliation data.');
        }

        $seenResultIds = [];
        foreach ($resultIds as $resultId) {
            if (!is_int($resultId) || $resultId < 1 || isset($seenResultIds[$resultId])) {
                throw new RuntimeException('Invalid monitor reconciliation data.');
            }

            $seenResultIds[$resultId] = true;
        }

        $nextPageAfterResultId = $page['next_page_after_result_id'] ?? null;
        if ($nextPageAfterResultId !== null && (!is_int($nextPageAfterResultId) || $nextPageAfterResultId < 1)) {
            throw new RuntimeException('Invalid monitor reconciliation data.');
        }
        if ($resultIds === [] && $nextPageAfterResultId !== null) {
            throw new RuntimeException('Invalid monitor reconciliation data.');
        }
        if ($resultIds !== [] && $nextPageAfterResultId !== $resultIds[array_key_last($resultIds)]) {
            throw new RuntimeException('Invalid monitor reconciliation data.');
        }

        return [
            'result_ids' => $resultIds,
            'has_more' => self::bool($page, 'has_more'),
            'next_page_after_result_id' => $nextPageAfterResultId,
        ];
    }

    /** @param array<string, mixed> $values */
    private static function arrayValue(array $values, string $key): array
    {
        if (!array_key_exists($key, $values) || !is_array($values[$key])) {
            throw new RuntimeException('Invalid monitor snapshot data.');
        }

        return $values[$key];
    }

    /** @param array<string, mixed> $values */
    private static function string(array $values, string $key): string
    {
        if (!array_key_exists($key, $values) || !is_string($values[$key])) {
            throw new RuntimeException('Invalid monitor snapshot data.');
        }

        return $values[$key];
    }

    /** @param array<string, mixed> $values */
    private static function nullableString(array $values, string $key): ?string
    {
        if (!array_key_exists($key, $values) || ($values[$key] !== null && !is_string($values[$key]))) {
            throw new RuntimeException('Invalid monitor snapshot data.');
        }

        return $values[$key];
    }

    /** @param array<string, mixed> $values */
    private static function bool(array $values, string $key): bool
    {
        if (!array_key_exists($key, $values) || !is_bool($values[$key])) {
            throw new RuntimeException('Invalid monitor snapshot data.');
        }

        return $values[$key];
    }

    /** @param array<string, mixed> $values */
    private static function positiveInt(array $values, string $key): int
    {
        $value = self::nonNegativeInt($values, $key);
        if ($value < 1) {
            throw new RuntimeException('Invalid monitor snapshot data.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function nonNegativeInt(array $values, string $key): int
    {
        if (!array_key_exists($key, $values) || !is_int($values[$key]) || $values[$key] < 0) {
            throw new RuntimeException('Invalid monitor snapshot data.');
        }

        return $values[$key];
    }

    /** @param array<string, mixed> $values */
    private static function finiteFloat(array $values, string $key): float
    {
        if (!array_key_exists($key, $values) || !is_float($values[$key]) || !is_finite($values[$key])) {
            throw new RuntimeException('Invalid monitor snapshot data.');
        }

        return $values[$key];
    }
}
