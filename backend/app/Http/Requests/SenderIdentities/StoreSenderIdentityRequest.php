<?php

namespace App\Http\Requests\SenderIdentities;

use Illuminate\Foundation\Http\FormRequest;

class StoreSenderIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_name' => ['required', 'string', 'max:255'],
            'from_email' => ['required', 'email', 'max:255'],
        ];
    }
}
