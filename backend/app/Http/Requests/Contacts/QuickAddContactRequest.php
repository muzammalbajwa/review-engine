<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by both the authenticated and guest quick-add endpoints —
 * "minimum fields only" (.claude/CLAUDE.md quick-add spec): a name, and
 * at least one of phone/email to actually send a review request to.
 */
class QuickAddContactRequest extends FormRequest
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
        ];
    }
}
