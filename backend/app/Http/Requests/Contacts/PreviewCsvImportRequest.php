<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;

class PreviewCsvImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Whole-file checks (size, mime/extension) belong here per
            // .claude/SECURITY.md #4; per-row/per-cell checks happen in
            // CsvImportService, since Form Request rules can't reasonably
            // express "every cell in every row".
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', config('csv_import.allowed_extensions')),
                'max:'.config('csv_import.max_file_size_kb'),
            ],
        ];
    }
}
