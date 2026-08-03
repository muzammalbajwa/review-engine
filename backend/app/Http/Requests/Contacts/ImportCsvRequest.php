<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;

class ImportCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', config('csv_import.allowed_extensions')),
                'max:'.config('csv_import.max_file_size_kb'),
            ],
            // Which detected CSV header maps to which contact field —
            // chosen by the tenant in the wizard's mapping step. Only
            // 'name' is required; a row missing it is skipped, not
            // rejected (CsvImportService).
            'mapping' => ['required', 'array'],
            'mapping.name' => ['required', 'string'],
            'mapping.phone' => ['nullable', 'string'],
            'mapping.email' => ['nullable', 'string'],
        ];
    }
}
