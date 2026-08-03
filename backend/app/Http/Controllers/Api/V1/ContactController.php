<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\ImportCsvRequest;
use App\Http\Requests\Contacts\PreviewCsvImportRequest;
use App\Models\Campaign;
use App\Models\Contact;
use App\Services\CsvImport\CsvImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(private readonly CsvImportService $csvImportService) {}

    /**
     * The wizard's "validation preview" step (.claude/FRONTEND.md) — parses
     * and validates but persists nothing, so the tenant can fix column
     * mapping/see flagged rows before committing.
     */
    public function previewImport(PreviewCsvImportRequest $request): JsonResponse
    {
        $parsed = $this->csvImportService->parse($request->file('file')->getRealPath());

        return response()->json(['data' => $parsed]);
    }

    public function import(ImportCsvRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->csvImportService->import(
            $request->file('file')->getRealPath(),
            $data['mapping'],
            Campaign::findOrCreateDefault()->id,
        );

        return response()->json(['data' => $result], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $contacts = Contact::query()->orderByDesc('id')->paginate(50);

        return response()->json(['data' => $contacts]);
    }
}
