<?php

namespace App\Services\Ai;

/**
 * Thrown when Claude's response can't be parsed as the strict JSON shape
 * the compliance checker requires. Distinct from
 * \Illuminate\Http\Client\RequestException (a network/HTTP-level failure)
 * so the caller can tell "Claude answered, but not usably" apart from
 * "Claude didn't answer at all" — both are surfaced as the same
 * ai_unavailable failure to the tenant (.claude/SECURITY.md #4: never leak
 * internals in responses), but are logged differently.
 */
class ComplianceCheckUnparseableException extends \RuntimeException {}
