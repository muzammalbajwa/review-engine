<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately does NOT use BelongsToTenant. Its tenant_id is the tenant
 * being *read* by an admin action, not the actor's own tenant — auto-filling
 * from CurrentTenant (the actor's tenant) would stamp every admin audit row
 * with the wrong tenant. tenant_id is set explicitly by whoever writes the
 * row (see App\Support\Audit\AdminAuditLogger).
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'actor_id',
        'action',
        'target',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // Nullable: some admin actions (e.g. "list all tenants") have no single
    // target tenant — see this table's own migration comment.
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
