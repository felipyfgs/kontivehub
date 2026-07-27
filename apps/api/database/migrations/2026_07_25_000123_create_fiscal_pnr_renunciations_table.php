<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fiscal_pnr_renunciations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('contributor_cnpj', 14);
            $table->bigInteger('renunciation_id');
            $table->string('status', 40)->default('UNKNOWN');
            $table->string('history_evidence_version', 64)->nullable();
            $table->string('status_evidence_version', 64)->nullable();
            $table->string('source_provenance', 32)->default('UNVERIFIED');
            $table->jsonb('summary_sanitized')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->timestampTz('refreshed_at')->nullable();
            $table->string('receipt_vault_object_id', 26)->nullable();
            $table->char('receipt_sha256', 64)->nullable();
            $table->string('receipt_mime_type', 100)->nullable();
            $table->integer('receipt_byte_size')->nullable();
            $table->timestampTz('receipt_observed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'status']);
            $table->unique(['tenant_id', 'client_id', 'renunciation_id'], 'fiscal_pnr_renunciations_tenant_id_client_id_renun_3ce9d2520d');
            $table->index(['tenant_id', 'contributor_cnpj']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_pnr_renunciations');
    }
};
