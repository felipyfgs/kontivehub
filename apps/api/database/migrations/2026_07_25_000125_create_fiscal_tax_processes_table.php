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
        Schema::create('fiscal_tax_processes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('contributor_cnpj', 14);
            $table->string('process_number', 80);
            $table->string('status', 40)->default('UNKNOWN');
            $table->string('evidence_version', 64)->nullable();
            $table->string('operation_key', 120)->nullable();
            $table->string('source_provenance', 40)->default('UNVERIFIED');
            $table->boolean('is_simulated')->default(false);
            $table->jsonb('summary_sanitized')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->timestampTz('refreshed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'process_number']);
            $table->index(['tenant_id', 'contributor_cnpj']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_tax_processes');
    }
};
