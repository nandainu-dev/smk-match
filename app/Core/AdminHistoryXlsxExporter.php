<?php
declare(strict_types=1);

namespace App\Core;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class AdminHistoryXlsxExporter
{
    /**
     * @param array{rows: list<array<string, mixed>>, summary: list<array{code: string, name: string, dominant_count: int, score_average_percentage: float}>} $report
     */
    public function export(array $report): Response
    {
        $spreadsheet = new Spreadsheet();

        try {
            $participants = $spreadsheet->getActiveSheet();
            $participants->setTitle('Participants');
            $this->writeSheet($participants, [
                'No',
                'Tanggal',
                'Nama Peserta',
                'Smp Dwiguna',
                'Kelas',
                'Nomor Telepon',
                'Jurusan',
            ], $this->participantRows($report['rows']));

            $results = $spreadsheet->createSheet();
            $results->setTitle('Results');
            $this->writeSheet($results, [
                'Participant name',
                'Campaign name',
                'Batch number',
                'Batch label',
                'Attempt status',
                'Attempt created at',
                'Outcome kind',
                'Outcome programs',
                'Display order',
                'Program code',
                'Program name',
                'Normalized percentage',
            ], $this->resultRows($report['rows']));

            $summary = $spreadsheet->createSheet();
            $summary->setTitle('Program Summary');
            $this->writeSheet($summary, [
                'Program code',
                'Program name',
                'Dominant count',
                'Average normalized percentage',
            ], $this->summaryRows($report['summary']));

            $spreadsheet->setActiveSheetIndex(0);
            $outputLevel = ob_get_level();
            ob_start();

            try {
                (new Xlsx($spreadsheet))->save('php://output');
                $body = (string) ob_get_clean();
            } finally {
                while (ob_get_level() > $outputLevel) {
                    ob_end_clean();
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return new Response($body, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="campaign-history.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param list<string> $headers @param list<list<int|float|string|null>> $rows */
    private function writeSheet(Worksheet $sheet, array $headers, array $rows): void
    {
        $this->writeRow($sheet, 1, $headers);
        $sheet->getStyle('A1:' . Coordinate::stringFromColumnIndex(count($headers)) . '1')
            ->getFont()
            ->setBold(true);
        $sheet->freezePane('A2');

        foreach ($rows as $index => $row) {
            $this->writeRow($sheet, $index + 2, $row);
        }

        foreach (range(1, count($headers)) as $columnIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }
    }

    /** @param list<int|float|string|null> $values */
    private function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $columnIndex => $value) {
            $cell = Coordinate::stringFromColumnIndex($columnIndex + 1) . $row;
            if (is_int($value) || is_float($value)) {
                $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
                continue;
            }

            $sheet->setCellValueExplicit($cell, $value ?? '', DataType::TYPE_STRING);
        }
    }

    /** @param list<array<string, mixed>> $reportRows @return list<list<int|float|string|null>> */
    private function participantRows(array $reportRows): array
    {
        $rows = [];
        foreach ($reportRows as $index => $row) {
            $rows[] = [
                $index + 1,
                $row['attempt_created_at'],
                $row['participant_name'],
                $row['origin_school'] ?? '',
                $row['class_name'] ?? '',
                $row['phone'] ?? '',
                $this->participantOutcomeProgramNames($row['outcome']),
            ];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $reportRows @return list<list<int|float|string|null>> */
    private function resultRows(array $reportRows): array
    {
        $rows = [];
        foreach ($reportRows as $row) {
            $outcome = $row['outcome'];
            if ($outcome === null || !is_array($outcome)) {
                continue;
            }

            foreach ($row['ranking'] as $rank) {
                $rows[] = [
                    ...$this->context($row),
                    $outcome['kind'],
                    $this->outcomePrograms($outcome),
                    $rank['display_order'],
                    $rank['program']['code'],
                    $rank['program']['name'],
                    $rank['normalized_percentage'],
                ];
            }
        }

        return $rows;
    }

    /** @param list<array{code: string, name: string, dominant_count: int, score_average_percentage: float}> $summary @return list<list<int|float|string|null>> */
    private function summaryRows(array $summary): array
    {
        $rows = [];
        foreach ($summary as $program) {
            $rows[] = [
                $program['code'],
                $program['name'],
                $program['dominant_count'],
                $program['score_average_percentage'],
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $row @return list<int|float|string|null> */
    private function context(array $row): array
    {
        return [
            $row['participant_name'],
            $row['campaign_name'],
            $row['batch_number'],
            $row['batch_label'],
            $row['attempt_status'],
            $row['attempt_created_at'],
        ];
    }

    /** @param array{kind: string, dominant_program: ?array{code: string, name: string}, tied_programs: list<array{code: string, name: string}>} $outcome */
    private function outcomePrograms(array $outcome): string
    {
        if ($outcome['kind'] === 'decisive') {
            return $outcome['dominant_program']['code'];
        }

        return implode(', ', array_map(
            static fn (array $program): string => $program['code'],
            $outcome['tied_programs'],
        ));
    }

    /** @param ?array{kind: string, dominant_program: ?array{code: string, name: string}, tied_programs: list<array{code: string, name: string}>} $outcome */
    private function participantOutcomeProgramNames(?array $outcome): string
    {
        if ($outcome === null) {
            return 'Belum selesai';
        }
        if ($outcome['kind'] === 'decisive') {
            return $outcome['dominant_program']['name'];
        }

        return implode(', ', array_map(
            static fn (array $program): string => $program['name'],
            $outcome['tied_programs'],
        ));
    }
}
