<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * .claude/CLAUDE.md webhook API spec. Same core shape as
 * QuickAddContactRequest (name, phone-or-email) plus the optional
 * external_id idempotency key. Real authorization (a valid, unexpired,
 * contacts:create-scoped API key) happens in route middleware
 * ('tenant' + 'abilities:contacts:create'), not here — matching this
 * codebase's convention of Form Requests validating shape, never acting
 * as the auth layer.
 */
class WebhookCreateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:32'],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
