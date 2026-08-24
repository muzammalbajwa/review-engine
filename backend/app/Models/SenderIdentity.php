<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SenderIdentity extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'from_name',
        'from_email',
        'verified',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
        ];
    }
}
