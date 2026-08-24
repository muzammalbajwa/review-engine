<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product-tour progress, tracked server-side and per-user (see the
 * 2026_08_10_120000_add_tour_progress_to_users_table migration's own
 * docblock for why: device/browser switches and multi-person tenants
 * both need this to survive past a single browser's localStorage).
 *
 * Two tiers, matching the two DB fields: the single welcome tour
 * (dashboard/nav walkthrough) and an open-ended map of contextual,
 * per-screen tours — no per-screen tour content exists yet, so `key`
 * below is validated by format only (route constraint), not an enum.
 */
class TourController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'has_completed_welcome_tour' => $user->has_completed_welcome_tour,
            'tours_seen' => $user->tours_seen ?? [],
        ]]);
    }

    public function completeWelcome(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->has_completed_welcome_tour = true;
        $user->save();

        return response()->json(['data' => [
            'has_completed_welcome_tour' => true,
        ]]);
    }

    /**
     * $key already passed the route's format constraint
     * ([a-z0-9_-]{1,40}) before this ever runs — see routes/api.php.
     */
    public function completeScreen(Request $request, string $key): JsonResponse
    {
        $user = $request->user();
        $user->markScreenTourSeen($key);

        return response()->json(['data' => [
            'tours_seen' => $user->tours_seen,
        ]]);
    }
}
