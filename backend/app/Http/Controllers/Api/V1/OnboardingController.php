<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StartTrialRequest;
use App\Models\Contact;
use App\Models\GbpConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .claude/FRONTEND.md: the guided onboarding flow. Chains existing,
 * already-tested endpoints (register, subscribe, GBP connect, CSV
 * import, templates) rather than duplicating any of their logic — this
 * controller only tracks *resumability* (which step to show) and nothing
 * about how any individual step actually works.
 */
class OnboardingController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $gbpConnection = GbpConnection::query()->first();

        return response()->json(['data' => [
            // 'pending' is the only status onboarding's plan step hasn't
            // been completed for — trialing/active/trial_expired all mean
            // a plan was picked at some point, so the wizard must never
            // send the tenant back to step 1 just because their trial
            // later expired (that would loop them forever: pick a plan ->
            // trial -> expires -> "pick a plan" again).
            'subscribed' => $tenant->status !== 'pending',
            'tenant_status' => $tenant->status,
            'plan' => $tenant->plan,
            'trial_ends_at' => $tenant->trial_ends_at,
            'gbp_status' => $gbpConnection?->status ?? 'not_connected',
            'gbp_step_done' => $tenant->gbp_step_done,
            'contacts_count' => Contact::query()->count(),
            'contacts_step_done' => $tenant->contacts_step_done,
            'completed' => $tenant->onboarding_completed_at !== null,
        ]]);
    }

    /**
     * The free-trial path through onboarding's plan step — no card, no
     * Stripe call (Tenant::startTrial()). The paid path (a tenant who
     * wants to skip the trial and pay immediately) still goes through the
     * existing POST /subscribe, which now also flips the tenant to
     * 'active' directly (see SubscriptionController::subscribe).
     */
    public function startTrial(StartTrialRequest $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        // Guards against a tenant re-hitting this endpoint after already
        // converting (or already trialing) resetting a real, billing
        // subscription back to a fresh 'trialing' state — the frontend
        // only ever shows this step while status is still 'pending', but
        // this is a real POST endpoint, reachable independent of that UI
        // gate.
        if ($tenant->status !== 'pending') {
            return response()->json([
                'error' => 'trial_already_started',
                'message' => 'You\'ve already picked a plan for this account.',
                'fields' => null,
            ], 422);
        }

        $tenant->startTrial($request->validated('plan'));

        return response()->json(['data' => [
            'tenant_status' => $tenant->status,
            'plan' => $tenant->plan,
            'trial_ends_at' => $tenant->trial_ends_at,
        ]], 201);
    }

    public function markGbpStepDone(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $tenant->gbp_step_done = true;
        $tenant->save();

        return response()->json(['data' => ['gbp_step_done' => true]]);
    }

    public function markContactsStepDone(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $tenant->contacts_step_done = true;
        $tenant->save();

        return response()->json(['data' => ['contacts_step_done' => true]]);
    }

    public function complete(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $tenant->onboarding_completed_at = now();
        $tenant->save();

        return response()->json(['data' => ['completed' => true]]);
    }
}
