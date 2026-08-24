<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\CheckComplianceRequest;
use App\Http\Requests\Templates\SaveTemplateRequest;
use App\Models\Campaign;
use App\Models\Template;
use App\Services\Ai\ComplianceChecker;
use App\Services\Ai\ComplianceCheckUnparseableException;
use App\Services\Templates\TemplateProvisioner;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * .claude/API.md: "Templates: CRUD /templates (save triggers compliance
 * check → may 422)". .claude/COMPLIANCE.md: the checker + "ship compliant
 * defaults" ("Default templates are pre-written, compliant, effective.
 * Editing is a power-user action. Most tenants never touch them.").
 *
 * No separate create/delete: steps are fixed at 1/2/3 (the product's 3
 * message drip) and auto-provisioned with compliant defaults the first
 * time they're read — the same "ship a working default" approach
 * ContactController takes with campaigns. "Editing" is always a save
 * (upsert) against one of the three existing steps.
 */
class TemplateController extends Controller
{
    private const STEPS = [1, 2, 3];

    public function __construct(
        private readonly ComplianceChecker $complianceChecker,
        private readonly TemplateProvisioner $templateProvisioner,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $campaign = Campaign::findOrCreateDefault();
        $this->templateProvisioner->ensureDefaults($campaign);

        $templates = Template::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('step')
            ->get();

        return response()->json(['data' => $templates]);
    }

    /**
     * Non-persisting — .claude/FRONTEND.md's "live compliance check with
     * inline pass/block" calls this as the tenant types, debounced, before
     * they ever click Save. Same check `save()` runs, just without the
     * write.
     */
    public function check(CheckComplianceRequest $request): JsonResponse
    {
        try {
            $result = $this->complianceChecker->check($request->validated('body'));
        } catch (RequestException|ComplianceCheckUnparseableException $e) {
            return $this->aiUnavailable($e);
        }

        return response()->json(['data' => $result]);
    }

    public function save(SaveTemplateRequest $request, int $step): JsonResponse
    {
        if (! in_array($step, self::STEPS, true)) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Unknown template step.',
                'fields' => null,
            ], 404);
        }

        $body = $request->validated('body');

        try {
            $result = $this->complianceChecker->check($body);
        } catch (RequestException|ComplianceCheckUnparseableException $e) {
            return $this->aiUnavailable($e);
        }

        if ($result['status'] === 'block') {
            // .claude/CLAUDE.md golden rule #4: "No message template can be
            // saved without passing the compliance checker." Not persisted
            // at all — the tenant's last-passing template (if any) stays
            // the live one untouched, and the friendly fix comes back here
            // for them to try again.
            return response()->json([
                'error' => 'compliance_block',
                'message' => 'This message did not pass the compliance check.',
                'fields' => null,
                'data' => [
                    'reasons' => $result['reasons'],
                    'suggested_rewrite' => $result['suggested_rewrite'],
                ],
            ], 422);
        }

        $campaign = Campaign::findOrCreateDefault();

        $template = Template::query()->firstOrNew(['campaign_id' => $campaign->id, 'step' => $step]);
        $template->campaign_id = $campaign->id;
        $template->step = $step;
        $template->body = $body;
        $template->compliance_status = 'pass';
        $template->compliance_reasons = [];
        $template->suggested_rewrite = null;
        $template->save();

        return response()->json(['data' => $template]);
    }

    private function aiUnavailable(\Throwable $e): JsonResponse
    {
        Log::error('Compliance check failed', ['error' => $e->getMessage()]);

        return response()->json([
            'error' => 'ai_unavailable',
            'message' => 'Could not check compliance right now. Try again shortly.',
            'fields' => null,
        ], 502);
    }
}
