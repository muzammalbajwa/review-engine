<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'contact_id',
        'step',
        'status',
        'scheduled_for',
        'sent_at',
        'provider_id',
        'click_token',
        'clicked_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'clicked_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
