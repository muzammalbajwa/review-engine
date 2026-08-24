<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .claude/QUEUE.md: tenant-facing alert when a GBP connection is revoked.
 * Nullable — null means "not yet alerted for the current revocation" (or
 * never revoked at all). Set once, the first time a revocation is
 * detected; cleared back to null on a successful reconnect so a *future*
 * revocation alerts again, rather than staying permanently silenced by
 * whatever the very first revocation ever set it to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gbp_connections', function (Blueprint $table) {
            $table->timestamp('revoked_alert_sent_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('gbp_connections', function (Blueprint $table) {
            $table->dropColumn('revoked_alert_sent_at');
        });
    }
};
