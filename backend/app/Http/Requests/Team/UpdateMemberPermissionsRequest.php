<?php

namespace App\Http\Requests\Team;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whitelisted against User::PERMISSION_RESOURCES explicitly so a client
 * can never smuggle in a 'billing' or 'team' key here (.claude/SECURITY.md
 * #1: never trust client input for anything that decides access).
 * Billing/team are enforced by EnsureTenantOwner checking role directly,
 * not a permission key — there is no key by that name for this endpoint
 * to ever accept.
 */
class UpdateMemberPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $unknownKeys = array_diff(array_keys($this->input('permissions', [])), User::PERMISSION_RESOURCES);

            if ($unknownKeys !== []) {
                $validator->errors()->add('permissions', 'Unknown permission key: '.implode(', ', $unknownKeys));
            }
        });
    }
}
