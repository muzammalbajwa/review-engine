<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'campaign_id',
        'step',
        'body',
        'compliance_status',
        'compliance_reasons',
        'suggested_rewrite',
    ];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'compliance_reasons' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
