<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Whitelisted against config/plans.php's interval keys — the
            // client picks monthly or annual, never a raw Paddle price ID
            // (.claude/SECURITY.md #1: a client-supplied value never
            // determines what gets charged). There's one plan now
            // (.claude/BILLING.md), so interval is the only choice left.
            'interval' => ['required', 'string', Rule::in(array_keys(config('plans.standard.intervals')))],
        ];
    }
}
