<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Backs POST /api/v1/contact (the public marketing-site contact form —
 * frontend/app/contact). No auth, no tenant — a prospect who hasn't
 * signed up has neither.
 *
 * `company` is the honeypot: a real visitor never sees or fills this
 * field (frontend/app/contact/ContactForm.tsx renders it visually
 * off-screen, not display:none — some bots skip display:none inputs
 * specifically to dodge this trick). Deliberately just `nullable|string`,
 * not `size:0` or similar — a validation error on a filled honeypot would
 * hand the bot a 422 telling it exactly which field tripped the check.
 * ContactMessageController reads it directly and decides silently instead.
 */
class SubmitContactMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'company' => ['nullable', 'string', 'max:255'],
        ];
    }
}
