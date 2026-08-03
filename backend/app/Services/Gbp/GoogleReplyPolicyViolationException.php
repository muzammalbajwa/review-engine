<?php

namespace App\Services\Gbp;

/**
 * Thrown when Google rejects a reply for violating its own content policies
 * (Phase 3 Step 3 — .claude/COMPLIANCE.md: "Surface Google's PolicyViolation
 * status on rejected auto-replies... No competitor shows this"). Distinct
 * from a transient/auth failure (\Illuminate\Http\Client\RequestException)
 * so the caller can tell "Google says no, permanently, until the content
 * changes" apart from "try again later."
 */
class GoogleReplyPolicyViolationException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Google rejected the reply: {$reason}");
    }
}
