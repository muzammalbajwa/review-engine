<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\CheckComplianceRequest;
use App\Http\Requests\Templates\SaveTemplateRequest;
use App\Models\Campaign;
use App\Models\Template;
use App\Services\Ai\ComplianceChecker;
use App\Services\Ai\ComplianceCheckUnparseableException;
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

    /**
     * Authored compliant by construction (no staff names, no star-rating
     * ask, no incentive, no conditional/gating language, same link for
     * everyone) — stored directly at compliance_status=pass rather than
     * verified through Claude at provisioning time, so a brand-new
     * tenant's default templates don't depend on the compliance checker
     * being reachable at all.
     */
    private const DEFAULT_BODIES = [
        1 => "Hi {name}, thank you for choosing {business_name}! We'd love to hear about your experience — please share a review here: {review_link}",
        2 => "Hi {name}, just a friendly reminder — we'd really appreciate your feedback on your recent experience with {business_name}: {review_link}",
        3 => "Hi {name}, it's been a while since we worked with you at {business_name}. We'd love to hear your thoughts — please leave us a review here: {review_link}",
    ];

    public function __construct(private readonly ComplianceChecker $complianceChecker) {}

    public function index(Request $request): JsonResponse
    {
        $campaign = Campaign::findOrCreateDefault();
        $this->ensureDefaultTemplates($campaign);

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

    private function ensureDefaultTemplates(Campaign $campaign): void
    {
        foreach (self::STEPS as $step) {
            $template = Template::query()->firstOrNew(['campaign_id' => $campaign->id, 'step' => $step]);

            if ($template->exists) {
                continue;
            }

            $template->body = self::DEFAULT_BODIES[$step];
            $template->compliance_status = 'pass';
            $template->compliance_reasons = [];
            $template->save();
        }
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
