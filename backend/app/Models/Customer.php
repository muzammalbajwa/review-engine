<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Laravel\Paddle\Customer as PaddleCustomer;

/**
 * Extends laravel/cashier-paddle's own Customer model (registered via
 * Cashier::useCustomerModel() in AppServiceProvider) purely to attach
 * BelongsToTenant — same reasoning as the previous processor's Customer
 * override: the package's real columns/behavior (paddle_id, name, email,
 * the billable morph) are untouched, this only adds the Eloquent-layer
 * global scope on top of the tenant_id + FORCE ROW LEVEL SECURITY the
 * migration already added (.claude/DATABASE.md/CLAUDE.md golden rule #2:
 * "Enforced by a global scope AND Postgres RLS. Both. Always.").
 *
 * tenant_id auto-fills from CurrentTenant — createAsCustomer() (Billable::
 * checkout()'s first step) always runs inside a normal, authenticated
 * POST /subscribe request, where CurrentTenant is already resolved by
 * SetTenantContext. PaddleWebhookController never creates a Customer row
 * itself (Paddle never sends a webhook this app needs to create one
 * from) — every webhook it does handle looks an existing Customer row up
 * by paddle_id instead, which is how it resolves tenant context in the
 * first place (see that controller's docblock).
 */
class Customer extends PaddleCustomer
{
    use BelongsToTenant;
}
