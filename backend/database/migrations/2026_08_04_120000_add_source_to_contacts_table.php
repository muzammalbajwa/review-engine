<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .claude/CLAUDE.md quick-add feature: distinguishes a contact created by
 * the quick-add form/link from one created via CSV import — same shape,
 * same campaign, same eventual send pipeline either way (both still
 * `status = 'pending'`, no separate code path). Plain string, no formal
 * DB enum, matching `status`'s own existing convention on this table —
 * this codebase doesn't use Postgres enum types anywhere.
 *
 * Existing rows default to 'csv_import': every contact that existed
 * before this migration was created that way — quick-add didn't exist
 * yet — so the default is a correct backfill, not a placeholder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('source')->default('csv_import')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
