<?php

/**
 * CSV upload caps (.claude/SECURITY.md #4: "Validate CSV uploads: size cap,
 * mime check, row cap, per-cell length cap, strip formulas"). No specific
 * numbers are given in the docs — these are reasonable defaults for a
 * past-customer list at the scale .claude/TESTING.md's drip-throttle gate
 * assumes (hundreds of contacts, not millions), tunable via env without a
 * code change.
 */
return [
    // Laravel's `max:` file rule is in kilobytes.
    'max_file_size_kb' => env('CSV_IMPORT_MAX_FILE_SIZE_KB', 5120), // 5 MB

    'max_rows' => env('CSV_IMPORT_MAX_ROWS', 10000),

    'max_cell_length' => env('CSV_IMPORT_MAX_CELL_LENGTH', 255),

    // Browsers report CSV inconsistently (text/csv, text/plain, or even
    // application/vnd.ms-excel from some Excel exports) — validated by
    // extension via Laravel's `mimes:` rule, which also sniffs content, not
    // just the client-supplied Content-Type header.
    'allowed_extensions' => ['csv', 'txt'],
];
