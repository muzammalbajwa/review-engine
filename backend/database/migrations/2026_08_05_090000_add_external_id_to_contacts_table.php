<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .claude/CLAUDE.md webhook API spec: "accept an optional external_id in
 * the payload, dedupe on (tenant_id, external_id) within a reasonable
 * window." Deliberately NOT a unique constraint — a unique index would
 * make that uniqueness permanent, not windowed. Dedup is an
 * application-level query (WebhookContactController: external_id match
 * AND created_at within the window), so this index exists purely to make
 * that lookup fast, not to enforce it at the database layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('source');
            $table->index(['tenant_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
