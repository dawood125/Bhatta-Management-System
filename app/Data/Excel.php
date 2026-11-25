<?php

namespace App\Data;

use DateTimeInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Reads and writes Excel files (.xlsx; .csv can be read too) for import and export.
 * Excel rather than CSV for downloads, because Excel opens a CSV with Urdu names as broken
 * characters unless it is told the encoding.
 */
class Excel
{
    /**
     * Write sheets to an .xlsx file.
     *
     * @param  array<string, array{headers: list<string>, rows: iterable<list<scalar|DateTimeInterface|null>>, widths?: array<int, float>}>  $sheets  sheet name => contents
     */
    public static function write(string $path, array $sheets): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true)->withBackgroundColor('E7E5E4');

        $first = true;
        foreach ($sheets as $name => $sheet) {
            $current = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $first = false;
            // Excel allows 31 characters and no : \ / ? * [ ] in a sheet name.
            $current->setName(mb_substr((string) preg_replace('/[:\\\\\\/?*\\[\\]]/', ' ', $name), 0, 31));

            foreach ($sheet['widths'] ?? [] as $column => $width) {
                $current->setColumnWidth($width, $column);
            }
            if (! isset($sheet['widths'])) {
                $current->setColumnWidthForRange(18, 1, max(1, count($sheet['headers'])));
            }

            $writer->addRow(Row::fromValuesWithStyle($sheet['headers'], $bold));
            foreach ($sheet['rows'] as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('d-m-Y') : $v, $row)));
            }
        }

        $writer->close();
    }

    /**
     * The rows of the first sheet, keyed by the header of each column (first row). Empty rows
     * are skipped; each row keeps its line number in the file.
     *
     * @return list<array{line: int, values: array<string, mixed>}>
     */
    public static function read(string $path, string $extension, int $maxRows = 2000): array
    {
        $reader = strtolower($extension) === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($path);

        $rows = [];
        $headers = null;
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = $row->toArray();

                    if ($headers === null) {
                        $headers = array_map(fn ($h) => self::normalise((string) $h), $cells);

                        continue;
                    }

                    if (array_filter($cells, fn ($v) => $v !== null && trim((string) ($v instanceof DateTimeInterface ? 'x' : $v)) !== '') === []) {
                        continue;
                    }

                    if (count($rows) >= $maxRows) {
                        throw new RuntimeException(__('The file has more than :count rows. Split it into smaller files.', ['count' => $maxRows]));
                    }

                    $values = [];
                    foreach ($headers as $index => $header) {
                        if ($header !== '') {
                            $values[$header] = $cells[$index] ?? null;
                        }
                    }
                    $rows[] = ['line' => $line, 'values' => $values];
                }

                break; // the first sheet only
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /** A new, private path for a file being built (removed after it is sent). */
    public static function tempPath(): string
    {
        $dir = storage_path('app/excel-temp');
        File::ensureDirectoryExists($dir, 0770);

        return $dir.'/'.Str::random(32).'.xlsx';
    }

    /** "Name (Urdu)" → "nameurdu": headers match whatever their case, spaces or brackets. */
    public static function normalise(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(trim($header)));
    }
}
