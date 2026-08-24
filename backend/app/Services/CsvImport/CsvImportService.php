<?php

namespace App\Services\CsvImport;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * .claude/SECURITY.md #4 CSV validation, applied here rather than at the
 * Form Request layer because these are per-row/per-cell checks, not
 * whole-request checks (file size + mime ARE whole-request checks and stay
 * in the Form Request, matching "Every route: Form Request validation ->
 * Policy authorization -> controller").
 */
class CsvImportService
{
    /**
     * Parses and validates without persisting anything — the "validation
     * preview" step of the wizard (.claude/FRONTEND.md). Formula-injection
     * stripping is NOT optional/preview-only: it happens here
     * unconditionally on every cell, so the preview the client sees is
     * exactly what would be stored, never the raw dangerous input.
     */
    public function parse(string $filePath): array
    {
        $maxRows = config('csv_import.max_rows');
        $maxCellLength = config('csv_import.max_cell_length');

        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open the uploaded file.');
        }

        $headers = fgetcsv($handle);

        if ($headers === false) {
            fclose($handle);

            return [
                'headers' => [],
                'rows' => [],
                'total_row_count' => 0,
                'row_cap_exceeded' => false,
            ];
        }

        $headers = array_map(fn ($h) => trim((string) $h), $headers);

        $rows = [];
        $rowCount = 0;
        $rowCapExceeded = false;

        while (($raw = fgetcsv($handle)) !== false) {
            $rowCount++;

            // The row cap protects against a single import blowing past
            // .claude/TESTING.md's drip-throttle assumptions and the
            // reactivation drip's own rate limits — reject the rest of the
            // file rather than silently importing only the first N rows,
            // which would look like data loss to the tenant.
            if ($rowCount > $maxRows) {
                $rowCapExceeded = true;
                break;
            }

            $rows[] = $this->parseRow($raw, $headers, $rowCount, $maxCellLength);
        }

        fclose($handle);

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total_row_count' => $rowCount,
            'row_cap_exceeded' => $rowCapExceeded,
        ];
    }

    private function parseRow(array $raw, array $headers, int $rowNumber, int $maxCellLength): array
    {
        $errors = [];
        $warnings = [];
        $data = [];

        foreach ($raw as $i => $cell) {
            $column = $headers[$i] ?? "column_{$i}";
            $cell = (string) $cell;

            // Formula-injection guard: a cell opening with = + - @ is a
            // formula in Excel/Sheets when the export is later opened
            // there. Prefixing with an apostrophe forces it to render as
            // inert text — applied unconditionally, not just flagged.
            if (preg_match('/^[=+\-@]/', $cell) === 1) {
                $cell = "'{$cell}";
                $warnings[] = "{$column}: neutralized a potential formula-injection payload";
            }

            if (mb_strlen($cell) > $maxCellLength) {
                $errors[] = "{$column}: exceeds the {$maxCellLength}-character limit";
            }

            $data[$column] = $cell;
        }

        return [
            // +1 for the header row, so this matches the line number a
            // tenant would see opening the file in a spreadsheet.
            'row_number' => $rowNumber + 1,
            'data' => $data,
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Persists only the rows that passed validation, mapped through the
     * client-chosen column mapping. Doesn't touch tenant_id: this always
     * runs inside an authenticated 'tenant' request, so BelongsToTenant's
     * auto-fill from CurrentTenant is exactly the auto-fill it exists for
     * (unlike AuthController's register()/login(), which run before any
     * tenant context exists at all).
     */
    public function import(string $filePath, array $mapping, int $campaignId): array
    {
        $parsed = $this->parse($filePath);

        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($parsed, $mapping, $campaignId, &$imported, &$skipped) {
            foreach ($parsed['rows'] as $row) {
                if (! $row['valid']) {
                    $skipped++;

                    continue;
                }

                $name = trim((string) ($row['data'][$mapping['name']] ?? ''));

                if ($name === '') {
                    $skipped++;

                    continue;
                }

                $contact = new Contact([
                    'campaign_id' => $campaignId,
                    'name' => $name,
                    'phone' => $this->mappedValue($row['data'], $mapping['phone'] ?? null),
                    'email' => $this->mappedValue($row['data'], $mapping['email'] ?? null),
                    'status' => 'pending',
                    'source' => 'csv_import',
                    // Treated as implied consent from the existing customer
                    // relationship the tenant is importing (Phase 2 is
                    // email-first per .claude/ROADMAP.md); SMS's stricter
                    // A2P consent requirements land with Phase 4.
                    'consent_at' => now(),
                ]);
                $contact->save();

                $imported++;
            }
        });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'total_row_count' => $parsed['total_row_count'],
            'row_cap_exceeded' => $parsed['row_cap_exceeded'],
        ];
    }

    private function mappedValue(array $data, ?string $column): ?string
    {
        if ($column === null || $column === '') {
            return null;
        }

        $value = trim((string) ($data[$column] ?? ''));

        return $value === '' ? null : $value;
    }
}
