<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Review extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'gbp_connection_id',
        'google_review_id',
        'rating',
        'text',
        'reviewer_name',
        'review_created_at',
        'needs_reply',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'review_created_at' => 'datetime',
            'needs_reply' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function gbpConnection(): BelongsTo
    {
        return $this->belongsTo(GbpConnection::class);
    }

    public function reply(): HasOne
    {
        return $this->hasOne(Reply::class);
    }
}
