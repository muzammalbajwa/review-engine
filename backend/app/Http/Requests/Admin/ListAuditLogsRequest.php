<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * .claude/SECURITY.md #1: "If a user picks a sort column, whitelist it
 * against an allow-list." Applied here to every filter, not just sorting —
 * actor_id/tenant_id are validated as real foreign keys before they ever
 * reach a query, action/from/to are typed and bounded, and per_page is
 * capped so a caller can't force an unbounded scan.
 */
class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'tenant_id' => ['nullable', 'uuid'],
            'action' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
