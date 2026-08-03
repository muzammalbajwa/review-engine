<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * contacts (.claude/DATABASE.md core tables). name/phone/email are capped
 * at 255 chars at the schema level too — belt-and-braces alongside the
 * application-level per-cell length cap enforced during CSV import
 * (.claude/SECURITY.md #4), since nothing stops a future write path from
 * bypassing the importer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('consent_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('campaign_id');
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON contacts');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON contacts');
        DB::statement('ALTER TABLE contacts NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE contacts DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('contacts');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE contacts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE contacts FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON contacts
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON contacts
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }
};
