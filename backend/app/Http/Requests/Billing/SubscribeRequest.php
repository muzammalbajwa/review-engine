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
            // Whitelisted against config/plans.php keys — the client picks a
            // plan, never a raw Stripe price ID (.claude/SECURITY.md #1: a
            // client-supplied value never determines what gets charged).
            'plan' => ['required', 'string', Rule::in(array_keys(config('plans')))],
            'payment_method' => ['required', 'string'],
        ];
    }
}
