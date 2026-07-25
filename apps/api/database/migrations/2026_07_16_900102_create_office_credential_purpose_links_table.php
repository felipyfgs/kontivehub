<?php

use App\Enums\OfficeCredentialPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculos de finalidade → credencial canônica (sem copiar PFX/senha).
 * Finalidades iniciais: SERPRO_TERM_SIGNING, NFE_AUTXML_DISTDFE.
 *
 * @see OfficeCredentialPurpose
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_credential_purpose_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_credential_id')
                ->constrained('office_credentials')
                ->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->string('status', 32)->default('ACTIVE');
            $table->timestampTz('linked_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['office_id', 'purpose', 'status'],
                'office_cred_purpose_links_lookup'
            );
            $table->index(
                ['office_credential_id', 'purpose'],
                'office_cred_purpose_links_credential'
            );
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX office_cred_purpose_links_one_active
                 ON office_credential_purpose_links (office_id, purpose)
                 WHERE status = 'ACTIVE'"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS office_cred_purpose_links_one_active');
        }

        Schema::dropIfExists('office_credential_purpose_links');
    }
};
