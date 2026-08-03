<?php

namespace App\Services\Ai;

use App\Models\Review;
use Illuminate\Support\Facades\Http;

/**
 * Drafts a reply to a Google review via the Claude API (.claude/CLAUDE.md:
 * "LLM: Claude API — compliance checks + review replies";
 * .claude/ROADMAP.md Phase 3: "Claude-generated replies"). No SDK — a plain
 * Http call to the Messages API, matching the rest of this codebase's
 * pattern of thin wrappers around external HTTP APIs (GoogleReviewsClient,
 * GoogleBusinessProfileClient) rather than pulling in a dependency for one
 * endpoint.
 */
class ClaudeReplyDrafter
{
    /**
     * Encodes Google's own reply guidelines plus this product's no-gating
     * stance (.claude/COMPLIANCE.md) so a reply can never itself become a
     * policy problem: it must never offer an incentive to change/remove a
     * review, never ask the reviewer to edit their review, and never
     * disclose the reviewer's personal information back to the public.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You draft public replies to Google Business Profile reviews for a
        local service business. Follow these rules strictly:

        1. Thank the reviewer genuinely. For critical reviews, acknowledge
           the specific concern raised — do not use generic boilerplate.
        2. Never offer a discount, refund, coupon, or any other incentive in
           exchange for changing, removing, or updating the review.
        3. Never ask the reviewer to edit, remove, or update their review.
        4. Never disclose the reviewer's personal information (phone,
           email, address, order/invoice numbers) in the public reply, even
           if they included it in their review.
        5. Do not invent contact details, staff names, or facts not present
           in the review. If inviting further contact, phrase it generically
           (e.g. "please reach out to us directly") without fabricating a
           channel.
        6. Keep the tone professional and warm, under 120 words, plain text
           only — no markdown, no quotation marks around the whole reply.
        7. Output ONLY the reply text itself, nothing else.
        PROMPT;

    public function draft(Review $review): string
    {
        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 400,
                'system' => self::SYSTEM_PROMPT,
                'messages' => [
                    ['role' => 'user', 'content' => $this->userPrompt($review)],
                ],
            ])
            ->throw();

        return trim($response->json('content.0.text', ''));
    }

    private function userPrompt(Review $review): string
    {
        return sprintf(
            "Star rating: %s\nReviewer name: %s\nReview text: %s\n\nDraft a reply to this review.",
            $review->rating !== null ? "{$review->rating}/5" : 'not given',
            $review->reviewer_name ?? 'not given',
            $review->text ?? '(no text, rating only)',
        );
    }
}
