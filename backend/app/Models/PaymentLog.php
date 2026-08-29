<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only row per real Paddle payment attempt this app is told
 * about — never updated after creation. See this table's own migration
 * for why it exists alongside (not instead of) Cashier's own
 * `transactions` table, and why the idempotency key is
 * (paddle_transaction_id, status) rather than paddle_transaction_id
 * alone. Written exclusively from PaddleWebhookController.
 */
class PaymentLog extends Model
{
    use BelongsToTenant;

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'tenant_id',
        'paddle_transaction_id',
        'amount',
        'currency',
        'status',
        'failure_reason',
        'billing_interval',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
