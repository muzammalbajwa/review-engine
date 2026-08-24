<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team permissions (owner/member two-tier structure within a tenant — this
 * is separate from the cross-tenant admin system, which is gated by
 * tenants.type, never touched here). Only meaningful for role='member' rows
 * — an owner's access is never gated by this column (User::hasPermission()
 * short-circuits true for an owner regardless of what's stored here).
 *
 * jsonb, same convention as users.tours_seen
 * (2026_08_10_120000_add_tour_progress_to_users_table.php) rather than a
 * separate permissions table — four independent boolean toggles
 * (contacts/templates/reviews/analytics) don't need a join table's
 * overhead, and this matches the one other per-user flag-map this app
 * already has. Keys absent from the map default to false (not granted) —
 * a freshly-invited member starts with zero access, the safe default,
 * never inheriting anything from the invite itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('permissions')->default('{}')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
