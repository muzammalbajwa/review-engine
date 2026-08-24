<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .claude/DATABASE.md's own messages table sketch lists `provider_id`, but
 * the actual 2026_08_03_150002 migration never added it — there was
 * nothing sending yet to need it. SendReviewRequest (the send job) needs
 * somewhere to record the mail provider's own message id, so a completed
 * send is traceable back to a real, specific delivery attempt rather than
 * just a status flip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('provider_id')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('provider_id');
        });
    }
};
