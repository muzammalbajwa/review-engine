<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'type',
        'status',
    ];

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    /**
     * No /campaigns CRUD exists yet — every tenant gets one auto-provisioned
     * "live" campaign the first time they need one (contact import,
     * template editing), the same "ship a working default, most tenants
     * never touch the underlying concept" approach .claude/COMPLIANCE.md
     * takes with message templates themselves. Shared here (rather than
     * duplicated per-controller) since both ContactController and
     * TemplateController need it. The empty conditions array still resolves
     * to "this tenant's campaigns only": TenantScope (BelongsToTenant) adds
     * that filter to every query regardless of what's explicitly passed
     * here.
     */
    public static function findOrCreateDefault(): self
    {
        return static::query()->firstOrCreate([], [
            'type' => 'live',
            'status' => 'active',
        ]);
    }
}
