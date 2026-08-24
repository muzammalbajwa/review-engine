<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation only — deliberately does NOT check "already a member /
 * already invited" here (same reasoning as RegisterRequest not checking
 * email uniqueness: that check has to run inside the controller's own
 * tenant-scoped query, not a declarative `unique:` rule that could race or
 * run under the wrong scope).
 */
class InviteTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
