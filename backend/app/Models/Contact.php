<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'campaign_id',
        'name',
        'phone',
        'email',
        'status',
        'source',
        'external_id',
        'consent_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
