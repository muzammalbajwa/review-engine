<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'campaign_id',
        'name',
        'phone',
        'email',
        'status',
        'consent_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
        ];
    }
}
