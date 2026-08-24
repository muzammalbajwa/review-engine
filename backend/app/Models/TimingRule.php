<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TimingRule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'delay_minutes_step2',
        'delay_minutes_step3',
        'business_hours_start',
        'business_hours_end',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'delay_minutes_step2' => 'integer',
            'delay_minutes_step3' => 'integer',
        ];
    }

    /**
     * .claude/QUEUE.md: "Never blast" — a tenant with no timing_rules row
     * yet gets a conservative, compliant default rather than the send job
     * having no rule to consult at all.
     */
    public static function findOrCreateDefault(): self
    {
        return static::query()->firstOrCreate([], [
            'delay_minutes_step2' => 60 * 24 * 3, // 3 days after message 1
            'delay_minutes_step3' => 60 * 24 * 7, // 7 days after message 2
            'business_hours_start' => '09:00:00',
            'business_hours_end' => '18:00:00',
            'timezone' => 'America/New_York',
        ]);
    }
}
