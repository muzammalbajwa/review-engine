<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Shape validation only — same "no email uniqueness check here" reasoning
 * as RegisterRequest. No email/business_name fields at all: unlike
 * registration, both come from the invite itself (the token), never from
 * this request body — a client could otherwise claim any tenant/email by
 * just filling in the form fields.
 */
class AcceptTeamInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
