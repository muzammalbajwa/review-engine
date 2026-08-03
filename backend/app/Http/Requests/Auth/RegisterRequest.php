<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Shape validation only — deliberately does NOT check email uniqueness
 * here. Laravel's `unique:` rule would run its query before the controller
 * has a chance to open the transaction + set the RLS auth-lookup bypass
 * flag (.claude/DATABASE.md), so under RLS it would silently pass even for
 * an email that already exists in another tenant. The controller checks
 * uniqueness itself, inside the properly-scoped transaction.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
