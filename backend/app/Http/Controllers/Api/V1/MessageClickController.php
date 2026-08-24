<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GbpConnection;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated (GET /api/v1/click/{token}) — clicked straight
 * out of a real customer's inbox, so it carries no Sanctum bearer token,
 * same class of exception as /gbp/callback, /lemon-squeezy/webhook, and the
 * sender-identity verify link. ResolveMessageClickTenant (route
 * middleware) has already format-validated the token, resolved the
 * owning tenant, and activated real tenant context by the time this
 * method runs — everything below queries under the normal, non-bypass
 * RLS policy, exactly like an authenticated request would.
 */
class MessageClickController extends Controller
{
    public function redirect(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $message = Message::query()->where('click_token', $token)->first();

        if ($message === null) {
            // Belt-and-suspenders: the middleware already resolved this
            // exact token to a tenant via the same column, so this should
            // always find the row. A miss here only means the row was
            // deleted between that lookup and this one (e.g. the contact
            // was deleted mid-request) — treated the same as "token
            // doesn't exist," never a different error that would leak
            // which case it was.
            abort(404);
        }

        // Atomic conditional UPDATE, not a read-then-write: a repeat visit
        // to the same link (a second real click, or an email client/link
        // scanner following it before the customer does) must set
        // clicked_at exactly once, never overwritten by whichever request
        // happens to run second. WHERE clicked_at IS NULL makes the second
        // (and every subsequent) request a genuine no-op at the database
        // level, not a race between a PHP-side read and write.
        Message::query()
            ->where('id', $message->id)
            ->whereNull('clicked_at')
            ->update(['clicked_at' => now()]);

        // .claude/COMPLIANCE.md rule #1: every contact's link goes to the
        // SAME destination — the tenant's one connected GBP review link,
        // read fresh here (not cached on the message at send time), never
        // branched on anything about this contact or this click.
        $connection = GbpConnection::query()
            ->where('status', 'connected')
            ->whereNotNull('review_link')
            ->first();

        if ($connection === null) {
            // The tenant disconnected GBP (or the connection lapsed)
            // sometime after this message was sent with a real link
            // embedded — rare, but a 302 to nowhere is worse than an
            // honest explanation. No frontend page exists to hand this
            // off to, so a small, clear API response instead.
            return response()->json([
                'error' => 'review_link_unavailable',
                'message' => 'This review link is no longer active. Please contact the business directly.',
                'fields' => null,
            ], 410);
        }

        return redirect()->away($connection->review_link);
    }
}
