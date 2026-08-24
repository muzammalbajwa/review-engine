<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sender_identities — the "from" address a tenant's messages actually send
 * from. `verified` gates the send job (Phase 2 Step 3): a message is never
 * sent from an unverified address, so a tenant can't (accidentally or
 * otherwise) send review requests as someone else's domain before proving
 * they control it. Verification itself uses Laravel's signed routes
 * (`URL::temporarySignedRoute()`), not a stored token column — nothing here
 * to leak or replay past the signature's own expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('from_name');
            $table->string('from_email');
            $table->boolean('verified')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
        });

        $this->enableRls();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON sender_identities');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_admin ON sender_identities');
        DB::statement('ALTER TABLE sender_identities NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sender_identities DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('sender_identities');
    }

    private function enableRls(): void
    {
        DB::statement('ALTER TABLE sender_identities ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sender_identities FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation ON sender_identities
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
        ");

        DB::statement("
            CREATE POLICY tenant_isolation_admin ON sender_identities
            USING (current_setting('app.is_admin', true) = 'true')
        ");
    }
};
