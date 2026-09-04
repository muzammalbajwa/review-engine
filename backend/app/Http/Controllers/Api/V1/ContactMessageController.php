<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\SubmitContactMessageRequest;
use App\Notifications\ContactMessageReceived;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The public marketing-site contact form (reviewengine.com/contact) — no
 * Sanctum auth, no tenant context, same class of exception as
 * QuickAddController (API.md). Named ContactMessageController, not
 * ContactController, to stay clearly separate from
 * Api\V1\ContactController (a tenant's own customer contacts —
 * unrelated domain, same English word).
 */
class ContactMessageController extends Controller
{
    public function store(SubmitContactMessageRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Honeypot: a real visitor never fills `company` (see the Form
        // Request's own docblock). Return the exact same success envelope
        // a genuine sender gets — never a 422/403 that would tell the bot
        // which field gave it away — but skip the actual send.
        if (filled($data['company'] ?? null)) {
            Log::info('contact.honeypot_triggered', ['ip' => $request->ip()]);

            return $this->successResponse();
        }

        $inbox = config('services.support.inbox');

        if (blank($inbox)) {
            // Fail closed, not open (same posture as the Paddle webhook's
            // unconditional signature check): a misconfigured recipient
            // must never be reported to the visitor as "sent" just
            // because nothing threw.
            Log::critical('contact.no_support_inbox_configured');

            return $this->failureResponse();
        }

        try {
            Notification::route('mail', $inbox)->notify(new ContactMessageReceived(
                $data['name'],
                $data['email'],
                $data['message'],
            ));
        } catch (\Throwable $e) {
            // Exactly the silent-failure shape this project has already
            // been bitten by elsewhere (compliance checker, register
            // flow): a caught send exception must produce a real error
            // response, never a "thanks!" the visitor has no reason to
            // doubt.
            Log::error('contact.send_failed', ['error' => $e->getMessage()]);

            return $this->failureResponse();
        }

        return $this->successResponse();
    }

    private function successResponse(): JsonResponse
    {
        return response()->json(['data' => [
            'message' => "Thanks — we'll get back to you soon.",
        ]], 201);
    }

    private function failureResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'send_failed',
            'message' => "We couldn't send your message right now. Try again in a moment, or email us directly.",
            'fields' => null,
        ], 502);
    }
}
