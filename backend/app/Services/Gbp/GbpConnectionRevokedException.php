<?php

namespace App\Services\Gbp;

use App\Models\GbpConnection;

/**
 * Thrown when Google tells us the refresh token no longer works
 * (`invalid_grant`) — the tenant revoked access, the Google account was
 * deleted/suspended, or the grant otherwise expired. Phase 3 Step 4 catches
 * this to alert the tenant instead of letting sync fail silently.
 */
class GbpConnectionRevokedException extends \RuntimeException
{
    public function __construct(public readonly GbpConnection $connection)
    {
        parent::__construct("GBP connection {$connection->id} (tenant {$connection->tenant_id}) has been revoked.");
    }
}
