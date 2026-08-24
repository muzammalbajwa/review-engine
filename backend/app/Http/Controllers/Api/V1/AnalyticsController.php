<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .claude/FRONTEND.md screen 3: campaign dashboard (sends, clicks,
 * reviews landed). Backed entirely by the `messages` table (Phase 2 Step
 * 1) — one real SQL aggregation query, not a PHP loop over rows, so this
 * stays O(1) round trips regardless of how many messages a tenant has.
 *
 * Tenant scoping is the same two layers as everywhere else: Message's
 * BelongsToTenant global scope adds `WHERE tenant_id = ?` (Layer A), and
 * even if that were forgotten, Postgres RLS on `messages` (FORCE ROW
 * LEVEL SECURITY) restricts which rows the aggregate FILTER clauses can
 * see in the first place (Layer B) — an aggregate is not a loophole
 * around RLS, it's still a query over the same row set. Proven, not
 * assumed: see AnalyticsAggregationTest's RLS-alone case.
 */
class AnalyticsController extends Controller
{
    public function campaign(Request $request): JsonResponse
    {
        $row = Message::query()
            ->selectRaw('
                COUNT(*) FILTER (WHERE sent_at IS NOT NULL) AS sends_total,
                COUNT(*) FILTER (WHERE sent_at IS NOT NULL AND step = 1) AS sends_step1,
                COUNT(*) FILTER (WHERE sent_at IS NOT NULL AND step = 2) AS sends_step2,
                COUNT(*) FILTER (WHERE sent_at IS NOT NULL AND step = 3) AS sends_step3,
                COUNT(*) FILTER (WHERE clicked_at IS NOT NULL) AS clicks_total,
                COUNT(*) FILTER (WHERE reviewed_at IS NOT NULL) AS reviews_total
            ')
            ->first();

        $sendsTotal = (int) $row->sends_total;
        $clicksTotal = (int) $row->clicks_total;
        $reviewsTotal = (int) $row->reviews_total;

        return response()->json(['data' => [
            'sends' => [
                'total' => $sendsTotal,
                'by_step' => [
                    '1' => (int) $row->sends_step1,
                    '2' => (int) $row->sends_step2,
                    '3' => (int) $row->sends_step3,
                ],
            ],
            'clicks' => [
                'total' => $clicksTotal,
                'rate' => $sendsTotal > 0 ? round($clicksTotal / $sendsTotal, 4) : 0,
            ],
            'reviews_landed' => [
                'total' => $reviewsTotal,
                'rate' => $sendsTotal > 0 ? round($reviewsTotal / $sendsTotal, 4) : 0,
            ],
        ]]);
    }
}
