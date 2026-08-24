<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billing and team management are owner-only, full stop — never
 * grantable to a member through the permissions system (User::hasPermission()
 * doesn't even have a 'billing' or 'team' key to grant; this middleware
 * checks role directly, not a toggleable permission, so there's no
 * combination of grants that ever lets a member through). Applied to
 * /subscribe, /subscription, /subscription/portal, and every /team/*
 * route except the public, pre-auth invite-lookup/accept endpoints (which
 * have no authenticated user at all yet).
 */
class EnsureTenantOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()->isOwner()) {
            return response()->json([
                'error' => 'owner_only',
                'message' => 'Only the account owner can do this.',
                'fields' => null,
            ], 403);
        }

        return $next($request);
    }
}
