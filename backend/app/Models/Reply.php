<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reply extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'review_id',
        'body',
        'posted_at',
        'policy_violation',
        'policy_violation_reason',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'policy_violation' => 'boolean',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
