<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Onboarding's plan step, no-card path. Same plan whitelist as
 * SubscribeRequest (a client picks a plan key, never a raw Stripe price
 * id — .claude/SECURITY.md #1) but no payment_method field at all: this
 * request exists specifically because the trial requires none.
 */
class StartTrialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', Rule::in(array_keys(config('plans')))],
        ];
    }
}
