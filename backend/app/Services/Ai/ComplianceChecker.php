<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * .claude/COMPLIANCE.md: "Before a template is saved, call the Claude API
 * to classify the text. BLOCK and return a friendly fix if it contains..."
 * Same no-SDK, thin-HTTP-wrapper pattern as ClaudeReplyDrafter — a plain
 * call to the Messages API, asked to answer in strict JSON so it can be
 * parsed deterministically rather than scraped from prose.
 */
class ComplianceChecker
{
    /**
     * Encodes .claude/COMPLIANCE.md's exact four block conditions, plus the
     * product's permanent no-gating constraint (.claude/CLAUDE.md golden
     * rule #3) restated here so the checker treats it as absolute, not
     * negotiable phrasing.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You classify a Google review request message template for a local
        service business against these rules. BLOCK the template if it
        contains ANY of:

        1. A request to name a specific staff member (e.g. "mention John in
           your review").
        2. A request for a specific star rating (e.g. "give us 5 stars").
        3. Any incentive tied to leaving a review — a discount, gift,
           refund, entry into a drawing, or loyalty points offered for
           reviewing.
        4. Conditional or gating language that routes the customer
           differently based on their sentiment (e.g. "if you had a great
           experience, click here", "if you're not happy, please contact us
           first instead"). Every customer must get the same review link
           regardless of how they feel — this rule has no exceptions.

        If none of these apply, the template passes.

        Respond with ONLY a single JSON object, no other text, no markdown
        fences, in exactly this shape:
        {"status": "pass" or "block", "reasons": ["short reason", ...], "suggested_rewrite": "a compliant rewrite, or null if status is pass"}

        "reasons" is an empty array when status is "pass". "suggested_rewrite"
        must preserve the original's tone and intent while removing only the
        violating language.
        PROMPT;

    /**
     * @return array{status: 'pass'|'block', reasons: list<string>, suggested_rewrite: ?string}
     *
     * @throws RequestException if Claude can't be reached
     * @throws ComplianceCheckUnparseableException if the response isn't the expected JSON shape
     */
    public function check(string $body): array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 500,
                'system' => self::SYSTEM_PROMPT,
                'messages' => [
                    ['role' => 'user', 'content' => $body],
                ],
            ])
            ->throw();

        $text = trim($response->json('content.0.text', ''));
        $parsed = json_decode($text, associative: true);

        if (
            ! is_array($parsed)
            || ! in_array($parsed['status'] ?? null, ['pass', 'block'], strict: true)
            || ! is_array($parsed['reasons'] ?? null)
        ) {
            throw new ComplianceCheckUnparseableException("Compliance checker returned an unparseable response: {$text}");
        }

        return [
            'status' => $parsed['status'],
            'reasons' => array_values(array_map('strval', $parsed['reasons'])),
            'suggested_rewrite' => $parsed['suggested_rewrite'] ?? null,
        ];
    }
}
