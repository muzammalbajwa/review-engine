<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\ImportCsvRequest;
use App\Http\Requests\Contacts\PreviewCsvImportRequest;
use App\Http\Requests\Contacts\QuickAddContactRequest;
use App\Models\Campaign;
use App\Models\Contact;
use App\Services\Contacts\ContactEnrollmentService;
use App\Services\CsvImport\CsvImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(
        private readonly CsvImportService $csvImportService,
        private readonly ContactEnrollmentService $contactEnrollmentService,
    ) {}

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

    /**
     * .claude/CLAUDE.md quick-add: "creates one contact and immediately
     * enrolls them in the existing drip engine's message-1 step. Reuse
     * that pipeline exactly" — the authenticated entry point (a tenant
     * using their own logged-in session on their phone, standing at a
     * job site). ContactEnrollmentService is the same creation path the
     * guest link (POST /quick/{token}) and the webhook API both use.
     */
    public function quickAdd(QuickAddContactRequest $request): JsonResponse
    {
        $data = $request->validated();

        $contact = $this->contactEnrollmentService->create(
            $data['name'],
            $data['phone'] ?? null,
            $data['email'] ?? null,
            'quick_add',
        );

        return response()->json(['data' => $contact], 201);
    }

    /**
     * Settings' "is this actually working" panel (.claude/FRONTEND.md) — a
     * tenant checking whether their Zapier/Make/CRM automation is actually
     * landing contacts, without digging into raw logs. Scoped to
     * source='webhook' specifically: CSV import and quick-add already have
     * their own immediate, visible confirmation (the import result screen,
     * the "Added." toast); this is the one entry point that otherwise has
     * none, since nothing happens in-browser when a third-party tool calls it.
     *
     * Excludes external_id's starting with the "send test event" button's
     * own prefix (below) — a tenant repeatedly testing their own connection
     * shouldn't inflate a number that exists specifically to answer "is my
     * *real* integration sending data." The test contact still lands in
     * the normal Contacts list either way; it just isn't counted here.
     */
    private const WEBHOOK_ACTIVITY_WINDOW_DAYS = 7;

    public const SETTINGS_TEST_EXTERNAL_ID_PREFIX = 'settings-test-';

    public function webhookActivity(Request $request): JsonResponse
    {
        $since = now()->subDays(self::WEBHOOK_ACTIVITY_WINDOW_DAYS);

        $base = Contact::query()
            ->where('source', 'webhook')
            ->where('created_at', '>=', $since)
            ->where(function ($query) {
                $query->whereNull('external_id')
                    ->orWhere('external_id', 'not like', self::SETTINGS_TEST_EXTERNAL_ID_PREFIX.'%');
            });

        $count = (clone $base)->count();

        $recent = (clone $base)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'name', 'external_id', 'created_at']);

        return response()->json([
            'data' => [
                'count' => $count,
                'window_days' => self::WEBHOOK_ACTIVITY_WINDOW_DAYS,
                'recent' => $recent,
            ],
        ]);
    }
}
